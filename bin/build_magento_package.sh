#!/bin/bash

if [ -z $TRAVIS_BUILD_DIR ]; then
    TRAVIS_BUILD_DIR=`pwd`
fi

# Download MagentoTarToConnect.phar
if [ ! -x /usr/local/bin/magento-tar-to-connect.phar ]; then
    curl -O https://raw.githubusercontent.com/astorm/MagentoTarToConnect/master/magento-tar-to-connect.phar
    chmod +x ./magento-tar-to-connect.phar
    sudo mv ./magento-tar-to-connect.phar /usr/local/bin/
fi

mkdir -p travis_release

# Create a tarball of the module
cp ./var/connect/package.xml .
tar -cf ./travis_release/Oyst_OneClick.tar app js lib skin package.xml

# Build package
magento-tar-to-connect.phar $TRAVIS_BUILD_DIR/build.config.php

rm $TRAVIS_BUILD_DIR/package.xml

echo -e "\033[32mBuild Magento package done.\033[0m"
