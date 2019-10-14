# Shel.Crawler for Neos CMS

Crawler for Neos CMS nodes and sites.
It can be used to warmup the caches after a release.

## Installation

Run the following command in your project

    composer require shel/crawler
    
## Usage

To crawl all document node types of a site run

    ./flow crawler:crawlnodes --siteNodeName <sitename>
    
To crawl all pages based on a single sitemap run

    ./flow crawler:crawlsitemap --url=http://huve.de.test/sitemap.xml --simultaneousLimit=10 --delay=0
    
To crawl all pages based on all sitemaps listed in a robots.txt file

    ./flow crawler:crawlrobotstxt --url=http://huve.de.test/robots.txt --simultaneousLimit=10 --delay=0        
