<?php

return array(
    // The base_dir and archive_file path are combined to point to your tar archive
    // The basic idea is a separate process builds the tar file, then this finds it
    'base_dir' => getcwd() . '/travis_release',
    'archive_files' => 'Oyst_OneClick.tar',

    // The Magento Connect extension name.  Must be unique on Magento Connect
    // Has no relation to your code module name.  Will be the Connect extension name
    'extension_name' => 'Oyst_OneClick',

    // Your extension version. By default, if you're creating an extension from a
    // single Magento module, the tar-to-connect script will look to make sure this
    // matches the module version.  You can skip this check by setting the
    // skip_version_compare value to true
    'extension_version' => '1.12.0',
    'skip_version_compare' => true,

    // You can also have the package script use the version in the module you
    // are packaging with.
    'auto_detect_version' => false,

    // Where on your local system you'd like to build the files to
    'path_output' => getcwd() . '/travis_release',

    // Magento Connect license value.
    'stability' => 'stable',

    // Magento Connect license value
    'license' => 'Apache',

    // Magento Connect channel value.  This should almost always (always?) be community
    'channel' => 'community',

    // Magento Connect information fields.
    'summary' => '<strong>Oyst 1-Click</strong> is a purchase button which enables your customers to buy in 1 clic directly from your product page.',
    'description' => '<strong>Oyst 1-Click</strong> helps e-commerce website to multiply their conversion rate by 2:<br />
<ul>
<li>Multiply your conversion rate by 2 on desktop and by 5 on mobile ;</li>
<li>Prevent cart abandonment between the product page and the payment confirmation-thrive thanks to Oyst community, our network effect will bring you more customers ;</li>
<li>A boon for purchases on mobile where conversion rate are particularly low.</li>
</ul>
Oyst 1-Click is powered by strong security technologies:<br />
<ul>
<li>Our technology Everkey uses cross-checking among 117 criteria (data science, biometrics) to prevent any fraud) ;</li>
<li>We have a partnership with Adyen, one of the global leaders of online payment (Uber, Blablacar, Airbnb).</li>
</ul>',
    'notes' => 'Check https://github.com/oystparis/oyst-1click-magento/releases/latest for details.',

    // Magento Connect author information. If author_email is foo@example.com, script will
    // prompt you for the correct name.  Should match your http://www.magentocommerce.com/
    // login email address
    'author_name' => 'Oyst',
    'author_user' => '1Click',
    'author_email' => 'plugin@oyst.com',

    // PHP min/max fields for Connect.  I don't know if anyone uses these, but you should
    // probably check that they're accurate
    'php_min' => '5.3.0',
    'php_max' => '8.0.0',
);
