#!/bin/bash

# To use it: send the text as argument, 1 arg = 1 line, for color, add a arg like: "color: green" or "color: #4d90fe"

# Slack web hook url from hooks.slack.com and setted in Travis CI
#SLACK_WEBHOOK_URL=""

if [ -z $SLACK_WEBHOOK_URL ]; then
    echo "Missing variable SLACK_WEBHOOK_URL or CHANNEL"
    exit 1
fi

TEXT=$1
if [ -z $TEXT ]; then
    echo "Need at least 1 arguments, the message."
    exit 1
fi

JSON="{\"text\": \"$TEXT\"}"

curl -s -d "payload=JSON" "$SLACK_WEBHOOK_URL"
