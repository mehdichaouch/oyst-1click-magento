#!/bin/bash

if [ -z $TRAVIS_TAG ]; then
    TRAVIS_TAG=$1
else
    TRAVIS_TAG=$(echo $TRAVIS_TAG | cut -c2-)
fi

if [ -z $TRAVIS_BUILD_DIR ]; then
    TRAVIS_BUILD_DIR=`pwd`
fi

# Get old version from package.xml
OLD_VERSION=$(cat $TRAVIS_BUILD_DIR/var/connect/package.xml | grep "<version>" | cut -f2 -d \> | cut -f1 -d \<)

sed "s/'extension_version' => '$OLD_VERSION',/'extension_version' => '${TRAVIS_TAG#v}',/g" build.config.php > build.config_tmp.php
rm -f build.config.php
mv -f build.config_tmp.php build.config.php

cd $TRAVIS_BUILD_DIR/var/connect/


# Create tmp package.xml and oyst_oneclick.xml with new version
sed 's/<version>'$OLD_VERSION'<\/version>/<version>'${TRAVIS_TAG#v}'<\/version>/g' package.xml > package_tmp.xml
sed 's/<version>'$OLD_VERSION'<\/version>/<version>'${TRAVIS_TAG#v}'<\/version>/g' Oyst_OneClick.xml > Oyst_OneClick_tmp.xml


# Delete old xml
rm -f package.xml Oyst_OneClick.xml


# Put tmp xml as real xml
mv package_tmp.xml package.xml
mv Oyst_OneClick_tmp.xml Oyst_OneClick.xml


echo -e "\033[32mUpdate Magento package version done.\033[0m"
