from datetime import datetime
import requests
import xmltodict
import os
import argparse
from swiftclient import Connection
from time import sleep


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
    return parser.parse_args()


def fetch_wiki_list(session: requests.Session) -> list[dict]:
    """Fetch the list of public wikis from WikiDiscover."""
    url = 'https://meta.miraheze.org/w/api.php'
    params = {
        'action': 'wikidiscover',
        'format': 'json',
        'wdstate': 'public',
        'wdsiteprop': 'dbname',
    }
    response = session.get(url, params=params)
    response.raise_for_status()
    return response.json().get('wikidiscover', [])


def fetch_sitemap_urls(session: requests.Session, wikis: list[dict]) -> list[str]:
    """Retrieve sitemap URLs from each wiki."""
    sitemaps = []
    rate_limit_backoff = 0
    down_attempts = 1

    for count, wiki_data in enumerate(wikis, start=1):
        wiki = wiki_data['dbname']
        sitemap_url = f'https://static.wikitide.net/{wiki}/sitemaps/sitemap.xml'

        print(f'[{count}/{len(wikis)}] Fetching: {sitemap_url}')

        while True:
            response = session.get(sitemap_url)

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

        try:
            sitemap_data = xmltodict.parse(response.text)
            sitemap_entries = sitemap_data.get('sitemapindex', {}).get('sitemap', [])
            if isinstance(sitemap_entries, dict):  # Handle single entry case
                sitemaps.append(sitemap_entries['loc'])
            else:
                sitemaps.extend(entry['loc'] for entry in sitemap_entries if 'loc' in entry)
        except Exception as e:
            print(f'Error processing {sitemap_url}: {e}')

        sleep(0.5)  # Prevent overwhelming the server

    return sitemaps


def generate_sitemap_index(sitemaps: list[str]) -> str:
    """Generate XML sitemap index content."""
    timestamp = datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ')
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
    
    with requests.Session() as session:
        print('Fetching wiki list...')
        wikis = fetch_wiki_list(session)
        print(f'Retrieved {len(wikis)} wikis.')

        print('Fetching sitemaps...')
        sitemap_urls = fetch_sitemap_urls(session, wikis)
        print(f'Retrieved {len(sitemap_urls)} sitemaps.')

        print('Generating sitemap index...')
        sitemap_index_content = generate_sitemap_index(sitemap_urls)

        print('Uploading to Swift...')
        upload_to_swift(args.auth, args.user, args.key, sitemap_index_content)

    print('Done.')


if __name__ == '__main__':
    main()
