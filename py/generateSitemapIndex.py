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
file = open('/mnt/mediawiki-static/sitemaps/sitemap.xml', 'w+') #resets the file
file.write('')
file.close()
file = open('/mnt/mediawiki-static/sitemaps/sitemap.xml', 'a+') # starts the appending
file.write('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
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
while l < len(maps):
    file.write('\n\t<sitemap>')
    url = '\n\t\t<loc>{0}</loc>'.format(str(maps[l]))
    file.write(url)
    date = datetime.now()
    dt_string = date.strftime("%Y-%m-%dT%H:%M:%SZ")
    loc = '\n\t\t<lastmod>{0}</lastmod>'.format(str(dt_string))
    file.write(loc)
    file.write('\n\t</sitemap>') # adds sitemap entry
    l = l + 1
file.write('\n</sitemapindex>') 
file.close() #done
print('done')
