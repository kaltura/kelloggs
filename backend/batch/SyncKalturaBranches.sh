#!/bin/bash

function log()
{
    echo "`/bin/date "+%Y-%m-%d %H:%M:%S"`: $1"
}

function goTo()
{
    log "Go to $1"
    cd $1
}

function purgeOldTags()
{
    log "Purging old server releases, which are older than 24 weeks!"
    find /opt/kaltura/*-*.*.* -type d -ctime +168 -exec rm -rf {} \;
}

goTo /opt/kaltura/server/

log "Pulling latest changes"
git pull

log "Listing latest available tag"
CURRENT_BRANCH=`git tag --sort=-creatordate  | head  -n 1 | sed 's/\-rel//g'`

log "Listing existing branches"
SYNCED_BRANCHES=`ls -ldf *-*.*.* | awk '{print $NF}'`

log "Current branch $CURRENT_BRANCH Synced Branches $SYNCED_BRANCHES"

if [[ $SYNCED_BRANCHES =~ $CURRENT_BRANCH  ]]; then
        log "Latest tag is already synced locally!"
else
        log "Syncing latest tag to local!"
        goTo /opt/kaltura/server/

        log "Getting latest changes!"
        git checkout $CURRENT_BRANCH

        log "Making current branch dir!"
        mkdir /opt/kaltura/$CURRENT_BRANCH

        log "Copy server dir do branch name"
        cp -rp . /opt/kaltura/$CURRENT_BRANCH
fi

purgeOldTags $SYNCED_BRANCHES