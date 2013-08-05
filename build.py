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
BUILD_DIR = os.path.join(ROOT_DIR, 'build')

if os.path.isdir(BUILD_DIR):
    shutil.rmtree(BUILD_DIR)

os.mkdir(BUILD_DIR)
curdir = os.getcwd()
os.chdir(ROOT_DIR)

try:
    files = [file for spec in FILESPEC for file in glob(spec)]

    for f in files:
        src = os.path.join(ROOT_DIR, f)
        dest = os.path.join(BUILD_DIR, f)
        destdir = os.path.dirname(dest)
        if not os.path.isdir(destdir):
            os.mkdir(destdir)
        shutil.copyfile(src, dest)

finally:
    os.chdir(curdir)
