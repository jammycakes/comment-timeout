# Build script for comment timeout

FILESPEC = [
    'js/**',
    'php/**',
    '*.php',
    '*.txt'
]

import os
import os.path
import shutil
from glob import glob

ROOT_DIR = os.path.abspath(os.path.dirname(__file__))
BUILD_DIR = os.path.join(ROOT_DIR, 'build', 'artifact')
SVN_DIR = os.path.join(ROOT_DIR, 'build', 'svn')
curdir = os.getcwd()
os.chdir(ROOT_DIR)

def update_version():
    pass

def remove_old_builds():
    if os.path.isdir(BUILD_DIR):
        shutil.rmtree(BUILD_DIR)

def build_artifact():
    os.mkdir(BUILD_DIR)
    files = [file for spec in FILESPEC for file in glob(spec)]
    for f in files:
        src = os.path.join(ROOT_DIR, f)
        dest = os.path.join(BUILD_DIR, f)
        destdir = os.path.dirname(dest)
        if not os.path.isdir(destdir):
            os.mkdir(destdir)
        shutil.copyfile(src, dest)

def upload_to_svn():
    pass

try:
    update_version()
    remove_old_builds()
    build_artifact()
    upload_to_svn()
finally:
    os.chdir(curdir)
