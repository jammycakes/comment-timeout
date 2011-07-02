@echo off
if "%1"=="" goto noargs
	svn copy http://plugins.svn.wordpress.org/comment-timeout/trunk http://plugins.svn.wordpress.org/comment-timeout/tags/%1 -m "Tag release %1"
	goto done
noargs:
	echo Usage: tag VERSION
	echo.
	echo Tags the latest release in Subversion as a numbered version.
done:
