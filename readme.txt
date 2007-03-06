Comment Timeout plugin for WordPress
====================================
version 1.3 beta 1

Copyright 2006 James McKay
http://www.jamesmckay.net/

Introduction
============
This plugin automatically closes comments on blog entries after a user-
configurable period of time. It has options which allow you to keep the
discussion open for longer on older posts which have had recent comments
accepted, or to place a fixed limit on the total number of comments in
the discussion. The latest version also allows you to block excessive
hyperlinks in posts, or to shut off comments and trackbacks to IP
addresses that have been reported by your spam queue or by Bad Behavior
as particularly problematic.


Installation
============
Copy the file CommentTimeout.php to your wp-content/plugins directory.


Configuration
=============
You can change various settings on the "Comment Timeout" page under the
"Options" tab on the WordPress dashboard.

The default settings are to disable comments and trackbacks on all posts
older than four months that have not had a comment for more than two months,
with no overall limit on the number of comments.


Features
========

1.  Active discussions can be kept open after the cut-off.
    You can set it to keep discussions open after the cut-off time where 
    there have been recent comments (by default, within the last 60 days).

2.  You can limit the length of a discussion.
    You can optionally set it to close the discussion after a certain
    number of comments have been posted.

3.  Pages, trackbacks and pingbacks can be included or excluded.
    You can apply it to pages and/or trackbacks and pingbacks as well as
    posts -- or not if you prefer.

4.  A configuration page
    You can configure how long you want to keep comments open on the
    administration page.

5.  It doesn’t alter the database.
    Comments are automatically closed without changing the status of the
    post in the database. If you deactivate this plugin, all such comments
    on your blog will be re-opened.
    
6.  New! Spam queue and Bad Behavior integration
    You can set it to automatically lock down troublesome IP addresses.
    Comment Timeout can now be configured to examine your spam queue and
    Bad Behavior logs for nefarious activity and shut off comments across
    the board from IP addresses that are causing you trouble.
    
7.  New! Excessive hyperlink blocking
    Version 1.3 has an option to block all spam containing three or more
    hyperlinks, and anything containing any BBCode hyperlinks at all.
    WordPress does not use BBCode out of the box, so it is very unlikely
    that turning this on will cause anyone problems. If a BBCode hyperlink,
    or too many HTML hyperlinks, are detected, the user will see an error
    message.

8.  New! User agent and IP address checking
    You can also check to make sure that comments have the same user agent
    string and IP address as the requesting page. The latter will stop any
    IP address spoofing spam bots, but it may cause problems for AOL users.

Comments that fail tests 7 and 8 will be rejected with an error message to
the user. 


Compatibility
=============
This is the alpha release of Comment Timeout 1.3, and while it is believed
to work as expected, it may contain some bugs. Please let me know of any
that you find.

This plugin requires WordPress version 2.0 or later; it is known not to work
with earlier versions (but you should upgrade anyway for security reasons).


Known issues
============
No distinction is made between comments, trackbacks and pingbacks in
calculating the cut-off date for commenting. This may cause comments to be
re-opened after a trackback if you leave trackbacks and pingbacks open.

After upgrading from version 1.3 alpha 1 or 1.3 alpha 2, the plugin will
reject all comments containing hyperlinks if it was previously set to allow
unlimited hyperlinks. To fix this, re-set the relevant option in the
configuration page.


Redistribution
==============
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA


Reporting bugs
==============
When reporting bugs, please provide me with the following information:

1. Which version of Comment Timeout you are using;
2. Which version of WordPress you are using;
3. The URL of your blog;
4. Which platform (Windows/IIS/PHP or Linux/Apache/MySQL/PHP) your server
   is running, and which versions of Apache and PHP you are using, if you
   know them;
5. The steps that need to be taken to reproduce the bug.


Happy blogging!

James McKay
the cool dude at james mckay dot net
http://www.jamesmckay.net/

Changelog
=========

2006-12-26:	Initial release
2006-12-28:	Changes for compatibility with Wordpress 2.1 alpha.
2006-12-29:	Bug fix: Corrected typo that made admin page inaccessible.
2007-01-27:	Bug fix: Corrected a bug that stopped it working on PHP 4.
2007-01-27:	Bug fix: Now closes comments correctly when ongoing discussion
		option is turned off.
2007-01-29:	Added rel="nofollow" control
2007-01-30:	Added extra granularity to rel="nofollow" control
2007-02-02:	Added spam queue/BB integration, excessive hyperlink blocking
		and User agent/IP checking
2007-03-06:	Bug fix release: you can now set a zero limit on hyperlinks;
		hyperlinks which contain line breaks are now counted correctly;
		warnings are no longer raised on servers where 
		allow_call_time_pass_reference is turned off in php.ini.
		