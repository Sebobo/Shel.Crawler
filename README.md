# Shel.Crawler for Neos CMS

Crawler for Neos CMS nodes and sites.
It can be used to warmup the caches after a release.

## Installation

Run the following command in your project

    composer require shel/crawler
    
## Usage
    
To crawl all pages based on a single sitemap run

    ./flow crawler:crawlsitemap --url=http://huve.de.test/sitemap.xml --simultaneousLimit=10 --delay=0
    
To crawl all pages based on all sitemaps listed in a robots.txt file

    ./flow crawler:crawlrobotstxt --url=http://huve.de.test/robots.txt --simultaneousLimit=10 --delay=0       
    
## Experimental node based crawling    

This command will try to generate all page html without using actual requests and only renders them internally.
Due to the complexity of the page context, this might not give the desired results, but the resulting 
html of alle crawled pages can be stored for further usage.

To make this work, you currently also have to set the `baseUri` for Neos by setting in the the `Settings.yaml` or 
by providing the `baseUri` in general via a environment variable.
See `Configuration/Production/Settings.yaml`. This works better with multisite setups.

    ./flow crawler:crawlnodes --siteNodeName <sitename>
    
### Experimental static file cache 
    
By providing the `outputPath` you can store all crawled content as html files. 

    ./flow crawler:crawlnodes --siteNodeName <sitename> --outputPath=Web/cache
    
You can use this actually as a super simple static file cache by adapting your webserver configuration.
There is an example for nginx:

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

You replace the existing `try_files` part with the given code and adapt the path `cache` if you use a different one.
This cache feature is really experimental and will later be replaced by an official package for Neos.
You are currently in charge of keeping the files up-to-date and removing old ones.

* Doesn't clear cache
* Doesn't update automatically on publish
* Ignores fusion caching configuration

## Contributing

Contributions or sponsorships are very welcome.
