from datetime import datetime
import requests
import xmltodict
import json
import sys
from urllib.parse import urlparse

reqsession = requests.Session()
print('getting wikilist')
URL = "https://meta.miraheze.org/w/api.php"
PARAMS = {
    "action": "wikidiscover",
    "format": "json",
    "wdstate": "public",
    "wdsiteprop": "dbname"
}
apirequest = reqsession.get(url=URL, params=PARAMS)
DATA = apirequest.json()
data = DATA['wikidiscover']
print('done, generating map!')
maps = []

for wikidata in data:
    wiki = wikidata['url']
    wiki = urlparse(wiki)
    wiki = str(wiki.netloc)
    urlreq = 'https://static.miraheze.org/sitemaps/{0}/sitemap.xml'.format(wiki)
    req = reqsession.get(url=urlreq)
    try:
        smap = xmltodict.parse(req.content)
    except Exception:
        print('Sitemap invalid, skipping "{0}"....'.format(wiki))
        continue
    try:
        maps.append(smap["sitemapindex"]["sitemap"]["loc"])  # Single items are not lists
    except TypeError:
        smap = smap["sitemapindex"]["sitemap"]
        for info in smap:
            try:
                maps.append(info["loc"])
            except KeyError:
                continue  # Somehow missing the data we need, ignore
    except KeyError:
        continue  # Sitemap data is not actually saved in this sitemap! Ignore

lines = []
lines.append('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
for map in maps:
    date = datetime.now()
    dt_string = date.strftime("%Y-%m-%dT%H:%M:%SZ")
    loc = '\n\t\t<loc>{0}</loc>'.format(str(map))
    lastmod = '\n\t\t<lastmod>{0}</lastmod>'.format(str(dt_string))
    lines.append('\t<sitemap>{0}{1}\n\t</sitemap>'.format(loc, lastmod))

lines.append('</sitemapindex>')

xmlfile = open('/mnt/mediawiki-static/sitemap.xml', 'r+') # makes xml
xmlfile.writelines(lines)

print('done')
