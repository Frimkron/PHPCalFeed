PHPCalFeed
==========

A simple PHP script for providing calendar feeds for your website in a variety 
of different formats including iCalendar, RSS, JSON and XML.

1. [Why](#1-why)
2. [Requirements](#2-requirements)
3. [User Guide](#3-user-guide)
4. [Licence](#4-licence)
5. [Credits](#5-credits)


1 Why?
------

Do you run a website with an events page? Does your site provide any kind of 
data feed for those events? If not, you might want to consider adding one. This
allows users to have your events appear directly on their calendar as you 
publish them, simplifying the process of discovering, making time for, and 
attending your event.

PHPCalFeed aims to make the addition of a calendar feed as easy as possible. It
is the "swiss army knife" of calendar feed setup; simple to drop into your site
and flexible enough to suit a wide variety of setups. You provide the event 
information in a single file, and the script serves it up to visitors in 
multiple different feed formats.


2 Requirements
--------------

### Essential

* Requires a webserver running PHP 5.3 or later
* Requires write permission to its directory on the webserver

### Optional

* PHP's JSON module is required for JSON input and output
* PHP's XML DOM module is required for XML and XHTML output
* PHP must be configured to allow remote requests in order to use remote file input
* PHP's OpenSSL module is required for remote requests over HTTPS


3 User Guide
------------


### 3.1 Installation

To install the script, copy the following files to your webserver using your
FTP client, SCP client or similar:

* __calendar.php__ (the PHP script)
* __calendar.xsd__ (schema definition for XML)
* __calendar-cal.css__ (stylesheet for HTML calendar)
* __calendar-sub.css__ (stylesheet for subscribe button)
* __calendar-sub.png__ (icon image for subscribe button)


### 3.2 Provide Event Info

The script can read event info from a __CSV__, __JSON__ or __ICalendar__ file. 
It can also extract event info from __HTML__. A CSV file is the simplest of 
these options. Events can be one-off occurrences or recurring events which 
repeat on a schedule. See the following sections for how to prepare the data 
file in your chosen format. 


#### 3.2.1 Local File

To have PHPCalFeed read from a file on your own server, create a file called 
`calendar-master` with the appropriate file extension (see following sections).
Copy the file to your webserver into the same directory as the `calendar.php` 
script. Delete the `calendar-config.php` file and `calendar.html` file if they 
are present. Now visit your website's calendar by entering its URL into the 
browser. For example:

	http://your-website.com/path-to-calendar/calendar.php

The script should detect the file, identify its format automatically, and 
populate your website's calendar.


#### 3.2.2 Remote File

As an alternative to a file on your own server, PHPCalFeed can read from a file
on a different server. This is useful if you wish to use another calendar feed
as the input, such as a public Google calendar (see 
[Google Calendar Input](#327-google-calendar-input) for more on this). Note 
that your URL should begin with the `http://` or `https://` protocol and 
__not__ `webcal://`. In order to connect to a remote URL, the `allow_url_fopen`
option must be enabled for your server's PHP installation. In addition, 
connecting to a secure `https://` URL requires the OpenSSL extension to be 
enabled for your installation.

To use a remote file, create the file `calendar-config.php` in the script 
directory, if it doesn't already exist, and define the `url` property by 
copying the code from the section below into the file, and replacing the 
example URL. Make sure to copy the code exactly, with the same letter cases, 
punctuation, etc:

```````````````````````````````````````````````````` php

<?php
return array(
	'url' => 'http://example.com/some-calendar.csv'
);

````````````````````````````````````````````````````

If the URL has the appropriate file extension (see following sections) then the
script will identify the file's format automatically. Otherwise, the format 
must be defined explicitly using the `format` property, as follows:

```````````````````````````````````````````````````` php

<?php
return array(
	'url' => 'http://example.com/some-calendar',
	'format'=>'csv-remote'
);

````````````````````````````````````````````````````

Now delete the `calendar.html` file if it already exists, and visit your 
website's calendar by entering its URL into the browser. For example:

	http://your-website.com/path-to-calendar/calendar.php
	
Your website's calendar will be populated from the remote file.


#### 3.2.3 CSV Input

CSV stands for Comma-Separated Values and is a simple text format compatible 
with most spreadsheet applications. To supply the event information in CSV 
format, use the `.csv` file extension - for example, `calendar-master.csv`. 
Your CSV file should contain columns as follows, in any order, each with a 
heading on the first row of the file:

* `name` __(required)__ - the title of the event
* `date` __(required)__ - either a one-off date in `yyyy-mm-dd` format, or the
  spec for a recurring event as described in the 
  [Event Recurrence Specification](#3210-event-recurrence-specification) section below.
  For example, `2014-02-28` or `weekly on thu`.
* `time` _(optional)_ - the time of day at which the event starts, in the following
  24 hour time format: `hh:mm`. For example, `21:30`. Defaults to midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a number
  of days, minutes and hours in the following format: `[0d][0h][0m]`. For example, 
  `3h 30m`. Defaults to 24 hours.
* `description` _(optional)_ - a description of the event
* `url` _(optional)_ - a link to more information about the event

Below is an example:

| Name            | Date                  | Time     | Description                         |
|-----------------|-----------------------|----------|-------------------------------------|
| Halloween Party | 2013-10-31            | 20:30    | Come and have a spooktacular time!  |
| Cool Society    | monthly on 1st tue    | 18:00    | Monthly meetup for cool people only |

PHPCalFeed can read CSV data directly from a Microsoft Outlook export. For more
information see [Microsoft Outlook CSV Input](#329-microsoft-outlook-csv-input)

By default, the script will assume the data in your CSV file is separated by 
comma `,` characters. For other separators such as tab or semicolon, create the
config file `calendar-config.php` if it doesn't already exist, and define the 
delimiting character with the "delimiter", as follows:

``````````````````````````````````````````` php

<?php
return array(
	'format' => 'csv-local',
	'delimiter' => "\t"      // a tab
);

```````````````````````````````````````````


#### 3.2.4 JSON Input

[JSON](http://json.org) is a simple data format using nested "objects" with
named "properties". Note that to use JSON input, the JSON and Multibyte String 
extensions must be enabled for your server's PHP installation.

To supply the event information in JSON format, use the file extension `.json` 
 - for example, `calendar-master.json`. Your JSON file should contain a root 
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
  in the following 24 hour time format: `hh:mm`. For example, `23:30`. Defaults to 
  midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a 
  string containing a number of days, hours and minutes as follows: `[0d][0h][0m]`.
  For example, `3h 30m`. Defaults to 24 hours.
* `description` _(optional)_ - a description of the event, as a string
* `url` _(optional)_ a link to more information about the event, as a string

Each recurring event in the `recurring-events` array should be an object with
the following properties:

* `name` __(required)__ - the title of the event, as a string
* `recurrence` __(required)__ - a string specifying how often the event occurs.
  For details of the format of this property see the 
  [Event Recurrence Specification](#3210-event-recurrence-specification) section below.
* `time` _(optional)_ - the time of day at which the event starts, as a string
  in the following 24 hour time format: `hh:mm`. For example, `23:30`. Defaults to 
  midnight.
* `duration` _(optional)_ - the length of time the event continues for, as a
  string containing a number of days, hours and minutes as follows: `[0d][0h][0m]`.
  For example, `3h 30m`. Defaults to 24 hours.
* `description` _(optional)_ - a description of the event, as a string
* `url` _(optional)_ a link to more information about the event as a string

Below is a complete example JSON file:

``````````````````````````````````````````````````````````````````````````````` json
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
```````````````````````````````````````````````````````````````````````````````


#### 3.2.5 ICalendar Input

ICalendar is an extensive calendar data format compatible with many 
applications. To use ICalendar format, use the file extension `.ics` - for 
example, `calendar-master.ics`. 

Your ICalendar file should contain a `VCALENDAR` object with one or more 
`VEVENT` objects. A full description of the ICalendar format is beyond the 
scope of this document, but for more information please refer to the 
[ICalendar RFC](http://tools.ietf.org/search/rfc5545).


#### 3.2.6 HTML Input

HTML is a document markup language used to deliver web pages to your internet 
browser. HTML content is more often concerned with the presentation of a page
rather than delivering meaningful data in a machine-readable format. PHPCalFeed
can, however, "scrape" event information from a page provided the relevant 
markup elements can be uniquely identified in the document. To use HTML format,
use the file extension `.htm` or `.html` - for example, `calendar-master.html`.
It is usually more useful to use a remote HTML file by its URL. See 
[Remote File](#322-remote-file) for more information.

By default, PHPCalFeed will look for event information embedded using the 
[hCalendar](http://microformats.org/wiki/hcalendar) microformat syntax.
Microformats are a standard for encoding semantic information in HTML, whereby 
page elements are given particular CSS `class` attributes to denote their 
meaning. As a minimum, the element surrounding each set of _event_ properties 
should be given the class `vevent`. Within each event element, the event's 
_name_ should be given the class `summary`. Its start time should be given the 
class `dtstart` - PHPCalFeed will attempt to interpret the date and time 
description as best it can. If any of the page elements already has a `class` 
attribute, simply add the new class to the end of the attribute, separated by a
space. Below is an example page with added hCalendar information:

```````````````````````````````````````````````````````` html

<html>
	<head>
		<title>Upcoming Events</title>
	</head>
	<body>
		<p>Here is the list of upcoming events for our group!<p>
		<table>
			<tbody>
				<tr class="big-yellow-text">
					<th>Date</th>
					<th>Description</th>
				</tr>
				<tr class="big-yellow-text vevent">
					<td>
						<abbr class="dtstart" title="2013-09-30 19:00:00">
							30th September at 7pm
						</abbr>
					</td>
					<td class="summary">
						Talk: The Deeper Meaning of Lolcats
					</td>
				</tr>
				<tr class="big-yellow-text vevent">
					<td>
						<abbr class="dtstart" title="2013-10-28 18:30:00">
							28th October at 6:30pm
						</abbr>
					</td>
					<td class="summary">
						Magic show with Bozo the clown
					</td>
				</tr>
			</tbody>
		</table>
	</body>
</html>

````````````````````````````````````````````````````````

The other supported properties are: 

* `description`
* `dtend`
* `duration`
* `url`

You can specify alternative class names for PHPCalFeed to look for instead. To 
do this, add the `html-markers` property to your `calendar-config.php` file. 
The css class names are specified as an associative array. For example:

```````````````````````````````````````````````` php

<?php
return array(
	'format' => 'html-local',
	'html-markers' => array(
		'cal-event-class' => 'calendar-event',
		'ev-name-class' => 'event-name',
		'ev-start-class' => 'event-time' 
	)
);


````````````````````````````````````````````````

The full list of customisable class properties is as follows:

* `cal-name-class` - the calendar title. Defaults to the page `<title>` contents.
* `cal-description-class` - the calendar description. Defaults to the page's `<meta>` 
  description contents.
* `cal-url-class` - the calendar's URL. Defaults to the URL of the page.
* `cal-event-class` - the element surrounding each set of event properties. Defaults 
  to `vevent`.
* `ev-name-class` - the event's name. Defaults to `summary`.
* `ev-description-class` - the event's description. Defaults to `description`.
* `ev-url-class` - the event's URL. Defaults to `url`.
* `ev-start-class` - the event's start date/time. Defaults to `dtstart`.
* `ev-end-class` - the event's end date/time. Defaults to `dtend`.
* `ev-duration-class` - the event's duration. Defaults to `duration`.

As a more advanced yet more flexible alternative to class names, you can 
specify an XPath expression for each element. XPath is a powerful and precise 
expression language for describing the location of content in an HTML or XML 
document. A good XPath primer can be found at 
[W3Schools](http://www.w3schools.com/xpath/), or for a detailed explanation 
check out the [W3C's XPath spec](http://www.w3.org/TR/xpath/). Below is an 
example of specifying the event elements using XPath expressions:

```````````````````````````````````````````````` php

<?php
return array(
	'format' => 'html-local',
	'html-markers' => array(
	
		// items within the list with id 'events'
		'cal-event-xpath' => "//ul[@id='events']/li",
		
		// the 'span' element with class 'evname' within the event element
		'ev-name-class' => "span[@class='evname']",
		
		// the second 'strong' element within the span with class 'evtime', 
		// within the event element
		'ev-start-class' => "span[@class='evtime']/strong[2]" 
	)
);


````````````````````````````````````````````````

The full list of customisable XPath properties is as follows:

* `cal-name-xpath` - the calendar title. Expression is relative to the document 
  root. Defaults to the page `<title>` contents.
* `cal-description-xpath` - the calendar description. Expression is relative to 
  the document root. Defaults to the page's `<meta>`
  description contents.
* `cal-url-xpath` - the calendar's URL. Expression is relative to the document 
  root. Defaults to the URL of the page.
* `ev-name-xpath` - the event`s name. Expression is relative to the event element.
  Defaults to element with `summary` class.
* `ev-description-xpath` - the event's description. Expression is relative to the 
  event element. Defaults to element with `description` class.
* `ev-url-xpath` - the event's url. Expression is relative to the event element.
  Defaults to element with `url` class.
* `ev-start-xpath` - the event's start date/time. Expression is relative to the 
  event element. Defaults to element with `dtstart` class.
* `ev-end-xpath` - the event's end  date/time. Expression is relative to the 
  event element. Defaults to element with `dtend` class.
* `ev-duration-xpath` - the event's duration. Expression is relative to the 
  event element. Defaults to element with `duration` class.

You can, of course, use a mix of class names and XPath expressions to specify
the desired HTML elements.


#### 3.2.7 Google Calendar Input

PHPCalFeed can read event information directly from a public Google calendar,
using remote ICalendar input. First you will need to obtain your calendar's 
URL. To do this:

#. Go to <http://www.google.com/calendar> and log in to Google Calendar
#. Click on the gear icon near the top right and choose "Settings"
#. Click on the "Calendars" tab just under the page heading
#. Click on the name of the calendar you'd like to use
#. Scroll down to the "Calendar Address" section
#. Click on the green "ICAL" button
#. Copy the URL from the popup dialog

Next, delete the `calendar-config.php` file in the calendar script's directory,
if it already exists, and create a new one. Set the `url` property to your 
Google calendar url, by copying the code exactly as it appears in the section 
below and replacing the example url:

``````````````````````````````````````````````` php

<?php
return array(
	'format' => 'icalendar-remote',
	'url' => 'http://your-calendar/url.ics'
);

```````````````````````````````````````````````

See the [Remote File](#322-remote-file) and 
[ICalendar Input](#325-icalendar-input) sections for more information.


#### 3.2.8 Yahoo Calendar Input

PHPCalFeed can read event information directly from a public Yahoo calendar,
using remote ICalendar input. First you will need to obtain your calendar's 
URL. To do this:

#. Go to <http://calendar.yahoo.com> and log in to Yahoo Calendar
#. Click on the "Actions" link with the gear beside it, above the calendar grid
#. Choose "Share..."
#. Select the calendar you'd like to use and click "Continue"
#. Copy the URL from the "Share with iCal Address" box

Next, delete the `calendar-config.php` file in the calendar script's directory,
if it already exists, and create a new one. Set the `url` property to your 
Yahoo calendar url, by copying the code exactly as it appears in the section 
below and replacing the example url:

`````````````````````````````````````````````` php

<?php
return array(
	'format' => 'icalendar-remote',
	'url' => 'http://your-calendar/url.ics'
);

``````````````````````````````````````````````

#### 3.2.9 Microsoft Outlook CSV Input

PHPCalFeed can read event data directly from a Microsft Outlook export file. To
export your event data from Outlook, follow the instructions below. These 
instructions are for Outlook 2010, but the process will be similar for your 
version of Outlook.

#. From the menu bar select "File"> "Open" > "Import"
#. A wizard will appear. Choose "Export to a file" from the list and click "Next"
#. Select "Comma Separated Values (Windows)" and click "Next"
#. Select "Calendar" and click "Next"
#. Click "Browse" and choose where to save the export file. Click "Next"
#. Click "Map Custom Fields"
#. Drag the following fields from the left box into the right box to select them:
	* Subject
	* Description
	* Start Date
	* Start Time
	* End Date
	* End Time
#. Click "OK" and then "Finish"
#. Enter the range of dates for which to export calendar events. Click "OK"
#. Outlook will take a moment to export the data to the selected file
#. Rename the exported file to `calendar-master.csv` if you have not done so 
   already.

Next, delete the `calendar-config.php` file in the calendar script's directory,
if it already exists. Copy the CSV file to the same directory as the 
`calendar.php` script on your server. Delete `calendar.html` and then visit the
calendar script in your browser to clear the cache. Your exported events should
be displayed.


#### 3.2.10 Event Recurrence Specification

In your input file you can specify an event that takes place on a recurring 
schedule, such as a social gathering that happens at the same time every week. 
PHPCalFeed uses a simple text-based format for specifying an event's schedule, 
which can be used in the [CSV](#323-csv-input) and [JSON](#324-json-input) 
input formats. 

The possible event recurrence options are laid out in full in 
the table below, where `nth` is a date between `1st` and `31st`, `ddd` is the 
first 3 letters of a day of the week, `mmm` is the first 3 letters of a month 
of the year, `n` is a number and `yyyy-mm-dd` is a date.

|----------------|-------|------------------------|---------------------|
| daily          |       |                        |                     |
|     - - -      |       |         - - -          |        - - -        |
| every n days   |       |                        | starting yyyy-mm-dd |
|     - - -      |       |         - - -          |        - - -        |
| weekly         | on    | ddd                    |                     |
|                |       | nth day                |                     |
|                |       | nth to last day        |                     |
|     - - -      |       |         - - -          |        - - -        |
| every n weeks  | on    | ddd                    | starting yyyy-mm-dd |
|                |       | nth day                |                     |
|                |       | nth to last day        |                     |
|     - - -      |       |         - - -          |        - - -        |
| monthly        | on    | nth day                |                     |
|                |       | nth to last day        |                     |
|                |       | nth ddd                |                     |
|                |       | nth to last ddd        |                     |
|     - - -      |       |         - - -          |        - - -        |
| every n months | on    | nth day                | starting yyyy-mm-dd |
|                |       | nth to last day        |                     |
|                |       | nth ddd                |                     |
|                |       | nth to last ddd        |                     |
|     - - -      |       |         - - -          |        - - -        |
| yearly         | on    | nth day                |                     |
|                |       | nth to last day        |                     |
|                |       | nth ddd                |                     |
|                |       | nth to last day        |                     | 
|                |       | nth of mmm             |                     |
|                |       | nth day of mmm         |                     |
|                |       | nth to last day of mmm |                     |
|     - - -      |       |         - - -          |        - - -        |
| every n years  | on    | nth day                | starting yyyy-mm-dd |
|                |       | nth to last day        |                     |
|                |       | nth ddd                |                     |
|                |       | nth to last ddd        |                     |
|                |       | nth of mmm             |                     |
|                |       | nth day of mmm         |                     |
|                |       | nth to last day of mmm |                     |
|                |       | nth ddd of mmm         |                     |
|                |       | nth to last ddd of mmm |                     |

Here are some examples:

* `daily` - every day
* `weekly on thu` - every Thursday
* `yearly on 8th may` - the 8th of May every year
* `yearly on 2nd to last wed of apr` - the second-to-last Wednesday of April, each year
* `every 2 weeks on 2nd to last day starting 2013-02-01` - every other Saturday, starting 
  with the one following the 1st February 2013


### 3.3 Linking to Feeds


#### 3.3.1 Subscribe Button

PHPCalFeed provides a handy subscribe button which you can add to your web 
pages, giving your visitors a number of different ways to subscribe to your 
events calendar.

To create your subscribe button, visit the calendar script in your web browser,
with the parameter `format=html-button` on the end of the URL:

	http://example.com/calendar.php?format=html-button
	
You will see what may appear to be a blank page. However, if you view the page
source you will find a block of HTML markup which can be copied and pasted into
your site's code. To make sure the button is styled correctly, you should add 
a link to the button's accompanying CSS file. Add the following code inside 
your page's `<head>` tag:

``````````````````````````````````````````````````````````````` html

<link rel="stylesheet" type="text/css" href="calendar-sub.css">


```````````````````````````````````````````````````````````````

You may need to adjust the `href` attribute above so that it points to the 
correct location that you installed the calendar script to. The subscribe 
button also requires the `calendar-sub.png` image file. You should make sure 
that this is present in the same directory as the calendar script.

You can edit `calendar-sub.png` and `calendar-sub.css` to change the look and 
feel of the subscribe button. For more information see the 
[Re-styling the HTML Calendar](#37-re-styling-the-html-calendar) section.


#### 3.3.2 Linking Directly to Feeds

As a more powerful alternative, you can also link direcly to the feeds 
generated by PHPCalFeed. To do this, simply use the URL of the script file, 
adding the parameter `format=` to indicate the format of data to access. For 
example, to link to an RSS feed, the following URL might be used:

	http://example.com/calendar.php?format=rss
	
The following data formats are available:

#### `icalendar`
iCalendar format - a standard calendar data exchange format compatible with 
iCal, Google Calendar, etc.

#### `rss`
RSS 2.0 format - a standard news aggregation format compatible with many news 
readers and other applications. Note that to generate this output, the libxml 
and DOM extensions must be enabled for your server's PHP installation.

#### `xml`
XML format - a popular and widely supported data exchange format. Note that to
generate this output, the libxml and DOM extensions must be enabled for your
server's PHP installation.

#### `json`
JSON format - another popular, widely supported data exchange format. Note that
to generate this output, the JSON and Multibyte String extensions must be 
enabled for your server's PHP installation.

#### `jsonp`
JSON wrapped in a function call - suitable for fetching via Javascript.
Use the `callback` parameter to specify the function name to use. Note that
to generate this output, the JSON and Multibyte String extensions must be 
enabled for your server's PHP installation.

#### `html`
HTML format (full) - a full webpage for users to view the event data 
directly in the browser. Note that to generate this output, the libxml and DOM 
extensions must be enabled for your server's PHP installation.

#### `html-frag`
HTML format (fragment) - just the HTML for the calendar itself,
suitable for embedding in another page. Note that to generate this output, the 
libxml and DOM extensions must be enabled for your server's PHP installation.

#### `html-button`
HTML format (subscribe button) - just the HTML for the calendar subscribe
button, suitable for embedding in anoter page. Note that to generate this 
output, the libxml and DOM extensions must be enabled for your server's PHP
installation.

#### `s-exp`
S-Expression format - a data format used by the Lisp programming language.

If no `format` parameter is specified, the appropriate format will be 
negotiated with the requesting client according to what it can support. When 
viewing in a browser, this will typically result in full HTML format.

When linking to the ICalendar feed, it is recommended to specify "webcal" as 
the protocol by prefixing the URL with `webcal://` rather than the usual 
`http://`. This will help the browser to open the feed in an 
ICalendar-compatible application.


### 3.4 Displaying in Another PHP Script

The event data can be included in HTML format in another PHP page. To do this,
simply include the PHP script using an `include` or `require` statement. For 
example:

``````````````````````````````````````````` php

<html>
	<body>
		<p>This is my page</p>
		<p>Here is a calendar:</p>
		
		<?php include "calendar.php"; ?>
	</body>
</html>

```````````````````````````````````````````


### 3.5 Caching Notes

The script generates static files in the various feed formats, so that they can
be served quickly. These files will be updated once per day, or if the source 
data file is updated (local file only).

The source format is cached to `calendar-config.php`. If the format of the 
source data is changed, (e.g. changing from CSV to JSON), this file should be 
removed.

To force the cache to clear, simply delete `calendar.html` and visit the script
in your browser. The other feed formats will be recreated along with the HTML 
output.

The subscribe button HTML is cached separately. Delete `calendar-button.html` 
and visit the script with `?format=html-button` to recreate it.

If the calendar script encounters an error when generating the feed files, the
error will be cached to `calendar.error` for 20 minutes. To force the script to
try again, delete this file and visit the script in the browser.


### 3.6 Renaming the Script File

The `calendar.php` script can be renamed if required. Note that the script will
expect the other accompanying files to be renamed too. For example, before 
renaming the set of files might look like this:

	calendar.php
	calendar.xsd
	calendar-cal.css
	calendar-sub.css
	calendar-sub.png
	calendar-config.php
	calendar-master.csv
	calendar.html
	calendar-frag.html
	calendar-button.html
	calendar.json
	calendar.xml
	calendar.ics
	calendar.rss
	calendar.lsp

But we could rename them as follows:

	events.php
	events.xsd
	events-cal.css
	events-sub.css
	events-sub.png
	events-config.php
	events-master.csv
	events.html
	events-frag.html
	events-button.html
	events.json
	events.xml
	events.ics
	events.rss
	events.lsp
	
	
### 3.7 Re-styling the HTML Calendar

The HTML version of the calendar feed (`format=html`) uses the Cascading 
Style Sheet file `calendar-cal.css` to apply its visual style. The generated 
HTML assigns different `class` attributes to the various page components which 
are referenced by this CSS file, making it easy to modify and thereby give your
calendar page a different look and feel.

The name and purpose of each CSS class is explained below:

* `cal-container` - the outermost container surrounding the title, description, 
  and calendar tables, and footer.
* `cal-title` - the header containing the name of the calendar
* `cal-description` - the block of text containing the calendar description
* `cal-calendar` - each of the tables representing a calendar month
* `cal-day` - each table cell representing a calendar day
* `cal-outside-day` - a table cell representing a day which falls outside of the
  current month
* `cal-today` - the table cell representing today's date
* `cal-date` - the label inside each table cell indicating that cell's day of
  the month
* `cal-month-title` - the label for each table indicating which month it 
  represents
* `cal-day-title` - the labels for each table column indicating which day of the
  week it represents
* `cal-events` - the container for each day's list of events
* `cal-event` - the container for each event
* `cal-nav-link` - the links to jump to each calendar month
* `cal-hcal-link` - the footer link to the hCalendar spec

In addition to these classes, each calendar table is given an `id` attribute:

* `cal-calendar-0` - the current month's calendar table
* `cal-calendar-1` - next month's calendar table
* `cal-calendar-2` - the month after next's calendar table

The subscribe button for the HTML calendar has its own CSS file, 
`calendar-sub.css`, so that it can be included in other pages separately. The 
name and purpose of each class defined in this file is explained below:

* `calsub-button` - the outermost container surrounding the button.
* `calsub-link` - the anchor element inside the button, allowing the button itself
  to be clicked as a link.
* `calsub-menu` - the popup menu containing the various subscribe options
* `calsub-item` - a subscribe option within the popup menu
* `calsub-icalendar`, `calsub-google`, `calsub-live`, `calsub-rss`, `calsub-json`,
  `calsub-xml`, `calsub-sexp` - these classes identify the individual subscribe 
  options.

The subscribe button image and the subscribe icons can be found in the CSS
spritesheet `calendar-sub.png`. 


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

