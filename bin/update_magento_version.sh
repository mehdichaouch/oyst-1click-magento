#!/bin/bash

if [ -z $TRAVIS_TAG ]; then
    TRAVIS_TAG="9.0.0" # ERROR NOT TAGGED COMMIT
else
    TRAVIS_TAG=$(echo $TRAVIS_TAG | cut -c2-)
fi


cd $TRAVIS_BUILD_DIR/var/connect/

# Get old version from package.xml
OLD_VERSION=$(cat package.xml | grep "<version>" | cut -f2 -d \> | cut -f1 -d \<)


# Check if version have change
if [[ $TRAVIS_TAG = $OLD_VERSION ]]; then
    echo -e "\033[31mError: version hasn't change since last time ($OLD_VERSION)\033[0m"
    exit 1
fi


# Check if version is greater than old one
#$(printf "$TRAVIS_TAG\n$OLD_VERSION" | sort -V | tac | head -n 1)
if [[ $(printf "$TRAVIS_TAG\n$OLD_VERSION" | sort -V | sed 'x;1!H;$!d;x' | head -n 1) != $TRAVIS_TAG ]]; then
    echo -e "\033[31mError: version has been downgraded ($TRAVIS_TAG < $OLD_VERSION)\033[0m"
    exit 1
fi


# Create tmp package.xml and oyst_oneclick.xml with new version
sed 's/<version>'$OLD_VERSION'<\/version>/<version>'$TRAVIS_TAG'<\/version>/g' package.xml > package_tmp.xml
sed 's/<version>'$OLD_VERSION'<\/version>/<version>'$TRAVIS_TAG'<\/version>/g' Oyst_OneClick.xml > Oyst_OneClick_tmp.xml


# Delete old xml
rm -f package.xml Oyst_OneClick.xml


# Put tmp xml as real xml
mv package_tmp.xml package.xml
mv Oyst_OneClick_tmp.xml Oyst_OneClick.xml


echo -e "\033[32mUpdate Magento package version done.\033[0m"
