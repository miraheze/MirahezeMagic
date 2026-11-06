from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import requests
import threading
import xmltodict
import os
import argparse
from swiftclient import Connection
from time import sleep

PROXY = 'http://bastion.fsslc.wtnet:8080'
TIMEOUT = (10, 30)

_local = threading.local()


def get_arguments() -> argparse.Namespace:
    """Parse command-line arguments."""
    parser = argparse.ArgumentParser(
        description=(
            'Generate sitemap index of all public wikis '
            "and upload it to the 'root' container in Swift."
        ),
    )
    parser.add_argument(
        '-A', '--auth', default=os.environ.get('ST_AUTH'),
        help='Swift authentication URL (ST_AUTH)',
    )
    parser.add_argument(
        '-U', '--user', default=os.environ.get('ST_USER'),
        help='Swift authentication user (ST_USER)',
    )
    parser.add_argument(
        '-K', '--key', default=os.environ.get('ST_KEY'),
        help='Swift authentication key (ST_KEY)',
    )
    parser.add_argument(
        '-w', '--workers', type=int, default=4,
        help='Number of concurrent sitemap fetches',
    )
    return parser.parse_args()


def make_session() -> requests.Session:
    """Create a session that goes out through the proxy."""
    session = requests.Session()
    session.proxies.update({'http': PROXY, 'https': PROXY})
    return session


def get_session() -> requests.Session:
    """Return the session for the current thread."""
    if not hasattr(_local, 'session'):
        _local.session = make_session()
    return _local.session


def fetch_wiki_list(session: requests.Session) -> list[dict]:
    """Fetch the list of public wikis from WikiDiscover."""
    url = 'https://meta.miraheze.org/w/api.php'
    params = {
        'action': 'query',
        'format': 'json',
        'list': 'wikidiscover',
        'wdlimit': '500',
        'wdprop': '',
        'wdstate': 'open|public|unlocked',
    }

    wikis = []
    offset = 0

    while True:
        if offset > 0:
            params['wdoffset'] = str(offset)

        print(f'Fetching with offset: {offset}')

        response = session.get(url, params=params, timeout=TIMEOUT)
        response.raise_for_status()

        data = response.json().get('query', {}).get('wikidiscover', {})
        wikis_data = data.get('wikis', {})
        count = data.get('count', 0)

        wikis.extend(wikis_data.values())

        if count == 0:
            break

        offset += len(wikis_data)

    return wikis


def fetch_wiki_sitemaps(wiki_data: dict, count: int, total: int) -> list[str]:
    """Retrieve the sitemap URLs of a single wiki."""
    session = get_session()
    wiki = wiki_data['dbname']
    sitemap_url = f'https://static.wikitide.net/{wiki}/sitemaps/sitemap.xml'

    print(f'[{count}/{total}] Fetching: {sitemap_url}')

    rate_limit_backoff = 0
    down_attempts = 1

    while True:
        try:
            response = session.get(sitemap_url, timeout=TIMEOUT)
        except requests.RequestException as e:
            wait_time = 3 * down_attempts
            print(f'Request error on {wiki} ({e}), retrying in {wait_time} seconds...')
            sleep(wait_time)
            down_attempts += 1
            continue

        if response.status_code == 429:  # Rate limited
            backoff_time = 1 + rate_limit_backoff
            print(f'Rate-limited on {wiki}, retrying in {backoff_time} seconds...')
            sleep(backoff_time)
            rate_limit_backoff += 1
            continue

        if response.status_code >= 500:  # Server is down
            wait_time = 3 * down_attempts
            print(f'Server error on {wiki}, retrying in {wait_time} seconds...')
            sleep(wait_time)
            down_attempts += 1
            continue

        break

    sleep(0.5)  # Prevent overwhelming the server

    if response.status_code != 200:  # Something went wrong
        print(f'Got status code {response.status_code} on {wiki}, skipping wiki')
        return []

    try:
        sitemap_data = xmltodict.parse(response.text)
        sitemap_entries = sitemap_data.get('sitemapindex', {}).get('sitemap', [])
        if isinstance(sitemap_entries, dict):  # Handle single entry case
            return [sitemap_entries['loc']]
        return [entry['loc'] for entry in sitemap_entries if 'loc' in entry]
    except Exception as e:
        print(f'Error processing {sitemap_url}: {e}')
        return []


def fetch_sitemap_urls(wikis: list[dict], workers: int) -> list[str]:
    """Retrieve sitemap URLs from each wiki."""
    total = len(wikis)

    with ThreadPoolExecutor(max_workers=max(1, workers)) as executor:
        results = executor.map(
            lambda item: fetch_wiki_sitemaps(item[1], item[0], total),
            enumerate(wikis, start=1),
        )
        return [url for urls in results for url in urls]


def generate_sitemap_index(sitemaps: list[str]) -> str:
    """Generate XML sitemap index content."""
    timestamp = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    sitemap_entries = '\n'.join(
        f'\t<sitemap>\n\t\t<loc>{loc}</loc>\n\t\t<lastmod>{timestamp}</lastmod>\n\t</sitemap>'
        for loc in sitemaps
    )
    return f'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{sitemap_entries}\n</sitemapindex>'


def upload_to_swift(auth: str, user: str, key: str, content: str) -> None:
    """Upload generated sitemap index to Swift storage."""
    conn = Connection(auth, user, key, retry_on_ratelimit=True)
    conn.put_object('root', 'sitemap.xml', contents=content, content_type='application/xml')
    print('Upload complete.')


def main():
    args = get_arguments()

    with make_session() as session:
        print('Fetching wiki list...')
        wikis = fetch_wiki_list(session)
        print(f'Retrieved {len(wikis)} wikis.')

    print('Fetching sitemaps...')
    sitemap_urls = fetch_sitemap_urls(wikis, args.workers)
    print(f'Retrieved {len(sitemap_urls)} sitemaps.')

    print('Generating sitemap index...')
    sitemap_index_content = generate_sitemap_index(sitemap_urls)

    print('Uploading to Swift...')
    upload_to_swift(args.auth, args.user, args.key, sitemap_index_content)

    print('Done.')


if __name__ == '__main__':
    main()
