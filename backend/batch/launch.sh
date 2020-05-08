#!/bin/bash

THIS_DIR=`dirname $0`

for i in `seq 1 30`
do
	php $THIS_DIR/TaskLauncher.php $THIS_DIR/../conf/kelloggs.ini $THIS_DIR/../conf/worker.ini kelloggsranger 3 >> /var/log/kelloggs/launcher.log 2>&1
	sleep 2
done
