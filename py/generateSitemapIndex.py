from datetime import datetime
import requests
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
while x < ld:
    wikidata = data[x]
    wiki = wikidata['url']
    wiki = wiki[8:]
    x = x+1 # url parser
    file.write('\n\t<sitemap>')
    url = '\n\t\t<loc>https://static.miraheze.org/sitemaps/{0}/sitemap.xml</loc>'.format(str(wiki))
    file.write(url)
    date = datetime.now()
    dt_string = date.strftime("%Y-%m-%dT%H:%M:%SZ")
    loc = '\n\t\t<lastmod>{0}</lastmod>'.format(str(dt_string))
    file.write(loc)
    file.write('\n\t</sitemap>') # adds sitemap entry
file.write('\n</sitemapindex>') 
file.close() #done
print('done')
