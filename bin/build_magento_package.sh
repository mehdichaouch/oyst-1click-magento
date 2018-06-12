#!/bin/bash

mkdir travis_release

# Get buildenv var
cd /tmp/mageteststand.* && BUILDENV=`pwd`

# Create folder
mkdir -p $BUILDENV/.modman/Oyst_OneClick/var/connect/

# Copy old .xml
cp -a $TRAVIS_BUILD_DIR/var/connect/* $BUILDENV/.modman/Oyst_OneClick/var/connect/

# Change permissions and execute mage
cd $BUILDENV/htdocs && chmod +x mage

# Build package
./mage package $TRAVIS_BUILD_DIR/var/connect/package.xml > /dev/null 2>&1

cp -a $TRAVIS_BUILD_DIR/var/connect/Oyst_OneClick-*.tgz $TRAVIS_BUILD_DIR/travis_release

echo -e "\033[32mBuild Magento package done.\033[0m"
