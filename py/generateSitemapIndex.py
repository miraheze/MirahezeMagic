from datetime import datetime
import requests
import xmltodict
import json
import sys
from urllib.parse import urlparse
from time import sleep

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
print('Got {0} wikis, generating index!'.format(len(data)))
maps = []
rls = 0
count = 0
hits = 1
for wikidata in data:
    count = count + 1
    if count == 10:
        print("Processed {0} wikis".format(count*hits))
        count = 0
        hits = hits + 1
    wiki = wikidata['dbname']
    urlreq = 'https://static.miraheze.org/{0}/sitemaps/sitemap.xml'.format(wiki)
    req = reqsession.get(url=urlreq)
    if req.status_code == 429:
        print("Rate Limited on {0} backing off for {1} seconds".format(wiki, 1+int(rls)))
        sleep(1+int(rls)) # sleep 1 second for every time rate limited
        req = reqsession.get(url=urlreq)
        rls = rls + 1
    try:
        smap = xmltodict.parse(req.content)
    except Exception as e:
        print('Caught exception {0}, skipping "{1}" - returned {2}....'.format(str(e), urlreq, req.status_code))
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
    sleep(0.5) # sleep half a second after each one
lines = []
lines.append('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
for map in maps:
    date = datetime.now()
    dt_string = date.strftime("%Y-%m-%dT%H:%M:%SZ")
    loc = '\n\t\t<loc>{0}</loc>'.format(str(map))
    lastmod = '\n\t\t<lastmod>{0}</lastmod>'.format(str(dt_string))
    lines.append('\t<sitemap>{0}{1}\n\t</sitemap>'.format(loc, lastmod))

lines.append('</sitemapindex>')

xmlfile = open('/mnt/mediawiki-static/sitemap.xml', 'w+') # makes xml
xmlfile.writelines(lines)

print('done')
