from datetime import datetime
import requests
import xmltodict
import os
import argparse
from swiftclient import Connection
from time import sleep

parser = argparse.ArgumentParser(
    description='Generate Miraheze sitemap index of all public wikis, and upload the object to the "root" container in Swift')
parser.add_argument(
    '-A', '--auth', dest='auth', default=os.environ.get('ST_AUTH'),
    help='URL for obtaining an auth token for Swift (ST_AUTH)')
parser.add_argument(
    '-U', '--user', dest='user', default=os.environ.get('ST_USER'),
    help='User name for obtaining an auth token for Swift (ST_USER)')
parser.add_argument(
    '-K', '--key', dest='key', default=os.environ.get('ST_KEY'),
    help='Key for obtaining an auth token for Swift (ST_KEY)')
args = parser.parse_args()

reqsession = requests.Session()
print('getting wikilist')
URL = 'https://meta.miraheze.org/w/api.php'
PARAMS = {
    'action': 'wikidiscover',
    'format': 'json',
    'wdstate': 'public',
    'wdsiteprop': 'dbname',
}
apirequest = reqsession.get(url=URL, params=PARAMS)
DATA = apirequest.json()
data = DATA['wikidiscover']
print(f'Got {len(data)} wikis, generating index!')
maps = []
rls = 0
count = 0
hits = 1
down = 1
for wikidata in data:
    count = count + 1
    if count == 10:
        print(f'Processed {count * hits} wikis')
        count = 0
        hits = hits + 1
    wiki = wikidata['dbname']
    urlreq = f'https://static.miraheze.org/{wiki}/sitemaps/sitemap.xml'
    req = reqsession.get(url=urlreq)
    if req.status_code == 429:
        print(f'Rate Limited on {wiki} backing off for {1 + int(rls)} seconds')
        sleep(1 + int(rls))  # sleep 1 second for every time rate limited
        req = reqsession.get(url=urlreq)
        rls = rls + 1
    while req.status_code == 502 or req.status_code == 503:  # miraheze might be down, pause for a bit
        secondswait = 3 * down
        down = down + 1
        print(f'Got 5xx error on {urlreq} - waiting for {secondswait} seconds')
        sleep(secondswait)
        req = reqsession.get(url=urlreq)
    try:
        smap = xmltodict.parse(req.content)
    except Exception as e:
        print(f'Caught exception {str(e)}, skipping "{urlreq}" - returned {req.status_code}')
        continue
    try:
        maps.append(smap['sitemapindex']['sitemap']['loc'])  # Single items are not lists
    except TypeError:
        smap = smap['sitemapindex']['sitemap']
        for info in smap:
            try:
                maps.append(info['loc'])
            except KeyError as e:
                print(f'Caught exception {str(e)}, "{urlreq}" may be partly ignored - got line {info}')  # Somehow missing the data we need, ignore
    except KeyError as e:
        print(f'Caught exception {str(e)} while parsing "{urlreq}"')  # Sitemap data is not actually saved in this sitemap! Ignore
    sleep(0.5)  # sleep half a second after each one
lines = '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
for sitemap in maps:
    date = datetime.now()
    dt_string = date.strftime('%Y-%m-%dT%H:%M:%SZ')
    loc = f'\n\t\t<loc>{sitemap}</loc>'
    lastmod = f'\n\t\t<lastmod>{dt_string}</lastmod>'
    lines += f'\n\t<sitemap>{loc}{lastmod}\n\t</sitemap>'

lines += '\n</sitemapindex>'

conn = Connection(args.auth, args.user, args.key, retry_on_ratelimit=True)
conn.put_object(
    'root',
    'sitemap.xml',
    contents=lines,
    content_type='application/xml',
)
print('done')
