PHPCalFeed
==========

A simple PHP script for providing calendar feeds for your website in a variety 
of different formats including iCalendar, RSS, JSON and XML.

1. [Why](#why)
2. [Requirements](#requirements)
3. [User Guide](#user-guide)
4. [Licence](#licence)
5. [Credits](#credits)


1 Why?
------

Do you run a website with an events page? Does your site provide any kind of 
feed for those events? If not, you might want to consider adding one. This 
allows users to have your events appear directly on their calendar as you 
publish them. Simplifying the process of discovering, making time for, and 
attending your event.

This script exists to make the addition to your website as easy as possible. 
You provide the event information in a single file, and the script serves it up
to visitors in multiple different feed formats.


2 Requirements
--------------

Requires a webserver serving PHP 5.


3 User Guide
------------


### 3.1 Installation

To install the script, copy the following files to your webserver using your
FTP client, SCP client or similar:

* __calendar.php__ (the PHP script)
* __calendar.xsd__ (schema definition for XML)
* __calendar.css__ (stylesheet for HTML)


### 3.2 Provide Event Info

The script can read event info from a __CSV__ or __JSON__ file, the former 
being the simpler option. Events can be one-off occurrences or recurring events 
which repeat on a schedule. See the following sections for how to prepare the 
data file in your chosen format. 

Once prepared, copy the file to your webserver into the same directory as the 
`calendar.php` script. Delete the `calendar-config.php` file if it is present.


#### 3.2.1 CSV Input

CSV is a simple text format compatible with most spreadsheet applications. To 
supply the event information in CSV format, create a file named 
`calendar-master.csv`. Your CSV file should contain columns as follows, in any 
order, each with a heading on the first row of the file:

* `name` __(required)__ - the title of the event
* `date` __(required)__ - either a one-off date in `yyyy-mm-dd` format, or the
  spec for a recurring event as described in the 
  [Event Recurrence Specification](#event-recurrence-specification) section below.
* `time` _(optional)_ - the time of day at which the event starts, in the following
  24 hour time format: `hh:mm`. Defaults to midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a number
  of days, minutes and hours in the following format: `[0d][0h][0m]`. Defaults to 
  24 hours.
* `description` _(optional)_ - a description of the event
* `url` _(optional)_ - a link to more information about the event


#### 3.2.2 JSON Input

[JSON](http://json.org) is a simple data format using nested "objects" with
named "properties". To supply the event information in JSON format, create
a file named `calendar-master.json`. Your JSON file should contain a root 
object with the following properties:

* `name` _(optional)_ - the title of the calendar, as a string
* `description` _(optional)_ - a description of the calendar, as a string
* `url` _(optional)_ - a link back to the calendar or related website, as a 
  string
* `events` _(optional)__ - an array of objects describing one-off events (see 
  below)
* `recurring-events`_(optional)_ - an array of objects describing recurring
  events (see below)

Each one-off event in the `events` array should be an object with the 
following properties:

* `name` __(required)__ - the title of the event, as a string
* `date` __(required)__ - the date on which the event starts, as a string in
  the following format: `yyyy-mm-dd`.
* `time` _(optional)_ - the time of day at which the event starts, as a string
  in the following 24 hour time format: `hh:mm`. Defaults to midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a 
  string containing a number of days, hours and minutes as follows: `[0d][0h][0m]`.
  Defaults to 24 hours.
* `description` _(optional)_ - a description of the event, as a string
* `url` _(optional)_ a link to more information about the event

Each recurring event in the `recurring-events` array should be an object with
the following properties:

* `name` __(required)__ - the title of the event, as a string
* `recurrence` __(required)__ - a string specifying how often the event occurs.
  For details of the format of this property see the 
  [Event Recurrence Specification](#event-recurrence-specification) section below.
* `time` _(optional)_ - the time of day at which the event starts, as a string
  in the following 24 hour time format: `hh:mm`. Defaults to midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a
  string containing a number of days, hours and minutes as follows: `[0d][0h][0m]`.
  Defaults to 24 hours.
* `description` _(optional)_ - a description of the event, as a string
* `url` _(optional)_ a link to more information about the event

Below is a complete example JSON file:

```json
{
	"name": "Mark's Calendar",
	"events": [
		{
			"name": "Super Fun Party",
			"date": "2013-02-28",
			"time": "20:30",
			"duration": "4h 30m"
		},
		{
			"name": "How to be Awesome - A Lecture",
			"date": "2013-09-10",
			"description": "A talk about how to be more awesome.",
			"url": "http://example.com/awesome"
		}
	],
	"recurring-events": [
		{
			"name": "Ada Lovelace Day",
			"recurrence": "yearly on 256th day",
			"description": "Celebrating the world's first computer programmer"
		}
	]
}
```


#### 3.2.3 Event Recurrence Specification

To create a recurring event, the event schedule must be specified. The possible
formats are laid out in full below, where `nth` is a date between `1st` and 
`31st`, `ddd` is the first 3 letters of a day of the week, `mmm` is the first 3
letters of a month of the year, `n` is a number and `yyyy-mm-dd` is a date.

	daily
	
	every n days starting yyyy-mm-dd
	
	weekly on ddd
	          nth day
	          nth to last day
	          
	every n weeks on ddd             starting yyyy-mm-dd
	                 nth day
	                 nth to last day
	          
	monthly on nth day
	           nth to last day
	           nth ddd
	           nth to last ddd
	           
	every n months on nth day         starting yyyy-mm-dd
	                  nth to last day
	                  nth ddd
	                  nth to last ddd
	           
	yearly on nth day
	          nth to last day
	          nth ddd
	          nth to last ddd
	          nth of mmm
	          nth day of mmm
	          nth to last day of mmm
	          nth ddd of mmm
	          nth to last ddd of mmm
	          
	every n years on nth day                starting yyyy-mm-dd
	                 nth to last day
	                 nth ddd
	                 nth to last ddd
	                 nth of mmm
	                 nth day of mmm
	                 nth to last day of mmm
	                 nth ddd of mmm
	                 nth to last ddd of mmm
	
Here are some examples:

* `daily` - every day
* `weekly on thu` - every Thursday
* `yearly on 8th may` - the 8th of May every year
* `yearly on 2nd to last wed of apr` - the second-to-last Wednesday of April, each year
* `every 2 weeks on 2nd to last day starting 2013-02-01` - every other Saturday, starting 
  with the one following the 1st February 2013


### 3.3 Linking to Feeds

To link to the feeds generated by the PHP script, simply use the URL of the 
script file, adding the parameter `format=` to indicate the format of data to 
access. For example, to link to an RSS feed, the following URL might be used:

	http://example.com/calendar.php?format=rss
	
The following data formats are available:

* `icalendar`: iCalendar format - a standard calendar data exchange format
  compatible with iOS and Google Calendar.
* `rss`: RSS 2.0 format - a standard news aggregation format compatible with
  many news readers and other applications.
* `xml`: XML format - a popular and widely supported data exchange format.
* `json`: JSON format - another popular, widely supported data exchange format.
* `html`: HTML format (full) - a full webpage for users to view the event data 
  directly in the browser.
* `html-frag`: HTML format (fragment) - just the HTML for the calendar itself,
  suitable for embedding in another page.

If no `format` parameter is specified, the appropriate format will be 
negotiated with the requesting client according to what it can support. When 
viewing in a browser, this will typically result in full HTML format.


### 3.4 Displaying in Another PHP Script

The event data can be included in HTML format in another PHP page. To do this,
simply include the PHP script using an `include` or `require` statement. For 
example:

``` php
<html>
	<body>
		<p>This is my page</p>
		<p>Here is a calendar:</p>
		
		<?php include "calendar.php"; ?>
	</body>
</html>
```

### 3.5 Caching Notes

The script generates static files in the various feed formats, so that they can
be served quickly. These files will be updated once per day, or if the source 
data file is updated.

The source format is cached to `calendar-config.php`. If the format of the 
source data is changed, (e.g. changing from CSV to JSON), this file should be 
removed.


4 Licence
---------

Released under the MIT licence. See the `LICENCE` file for the full text of 
this licence.


5 Credits
---------

Written by Mark Frimston

* Twitter: [@frimkron](http://twitter.com/frimkron)
* Email: <mfrimston@gmail.com>
* Github: <http://github.com/Frimkron/PHPCalFeed>

