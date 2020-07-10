from datetime import datetime
import requests
import xmltodict
import json
import sys
S = requests.Session()
print('getting wikilist')
URL = "https://meta.miraheze.org/w/api.php"
PARAMS = {
	"action": "wikidiscover",
	"format": "json",
	"wdstate": "public",
	"wdsiteprop": "dbname"
}
R = S.get(url=URL, params=PARAMS)
DATA = R.json()
data = DATA['wikidiscover']
ld = len(data)
x = 0 # gets the api data
print('done, generating map!')
maps = []
while x < ld:
    wikidata = data[x]
    wiki = wikidata['url']
    wiki = wiki[8:]
    x = x+1 # url parser
    urlreq = 'https://static.miraheze.org/sitemaps/{0}/sitemap.xml'.format(str(wiki))
    req = S.get(url=urlreq)
    try:
        smap = xmltodict.parse(req.content)
        cont = 1
    except :
        print('Sitemap invalid, skipping....')
        cont = 0
    if cont == 1:
        try:
            smap = smap["sitemapindex"]["sitemap"]
        except:
            continue
        z = 0
        for y in smap:
            try:
                info = smap[z]
            except KeyError:
                continue
            maps.append(info["loc"])
            z = z + 1
l = 0
with open('/mnt/mediawiki-static/sitemaps/sitemap.xml', 'w+') as xmlfile:
	xmlfile.write('') #resets file to blank
with open('sitemap.xml', 'a+') as xmlfile:  # makes xml
    xmlfile.write(
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
    while l < len(maps):
        date = datetime.now()
        dt_string = date.strftime("%Y-%m-%dT%H:%M:%SZ")
        loc = '\n\t\t<loc>{0}</loc>'.format(str(maps[l]))
        lastmod = '\n\t\t<lastmod>{0}</lastmod>'.format(str(dt_string))
        xmlfile.write('\n\t<sitemap>{0}{1}\n\t</sitemap>'.format(str(loc), str(lastmod)))
        l = l + 1
    xmlfile.write('\n</sitemapindex>')
print('done')
