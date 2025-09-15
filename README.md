# Shel.Crawler for Neos CMS

Crawler for Neos CMS nodes and sites.
It can be used to warm up the caches after a release or dump your site as html files.

## Installation

Run the following command in your project

    composer require shel/crawler
    
## Usage
    
To crawl all pages based on a single sitemap run

```console
./flow crawler:crawlsitemap --url=http://huve.de.test/sitemap.xml --simultaneousLimit=10 --delay=0
```
    
To crawl all pages based on all sitemaps listed in a robots.txt file

```console
./flow crawler:crawlrobotstxt --url=http://huve.de.test/robots.txt --simultaneousLimit=10 --delay=0
```
    
## Node based crawling    

This command will try to generate all page html without using actual requests and only renders them internally.
Due to the complexity of the page context, this might not give the desired results, but the resulting 
html of alle crawled pages can be stored for further usage.

This can be much faster as all pages are rendered in one process and all caches are reused.

To make this work, you need make provide a valid hostname. 

This can be done via one of the following ways:

* have an active domain setup for a site (recommended, the crawler will use the first active domain)
* set the `Neos.Flow.http.baseUri` setting for Neos in your `Settings.yaml`
* provide the `baseUri` in general via the environment variable `CRAWLER_BASE_URI` and use the example in `Configuration/Production/Settings.yaml`

```console
./flow crawler:crawlnodes --siteNodeName <sitename>

# The default preset will be crawled, provide a dimension (combination) if you need a specific dimension to be crawled
./flow crawler:crawlnodes --siteNodeName <sitename> --dimensions language:en
./flow crawler:crawlnodes --siteNodeName <sitename> --dimensions language:en,country:de
```

To crawl all sites based on their primary active domain:

```console
./flow crawler:crawlsites       
```

To crawl all sites based on their primary active domain and use the URLs listed in robots.txt:

```console
./flow crawler:crawlsites --method robotstxt
```

### Experimental static file cache 
    
By providing the `outputPath` you can store all crawled content as html files. 

```console
./flow crawler:crawlnodes --siteNodeName <sitename> --outputPath=Web/cache
```
    
You can use this actually as a super simple static file cache by adapting your webserver configuration.
There is an example for nginx:

```nginx
# Serve a cached page matching the request if it exists 
location / {
    default_type "text/html";
    try_files /cache/$uri $uri $uri/ /index.php?$args;
}

# Serve cache/index(.html) instead of / if it exists
location = / {
    default_type "text/html";
    try_files /cache/index.html /cache/index /index.php?$args;
} 
```

You replace the existing `try_files` part with the given code and adapt the path `cache` if you use a different one.
This cache feature is really experimental, and you are currently in charge of keeping the files up-to-date and removing old ones.

* Doesn't clear cache
* Doesn't update automatically on publish
* Ignores Fusion caching configuration
* Shortcuts are ignored (open TODO)

## Contributing

Contributions or sponsorships are very welcome.
