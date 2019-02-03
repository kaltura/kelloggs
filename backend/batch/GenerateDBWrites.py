import socket
import time
import sys
import os

def writeLog(msg):
	print '%s %s' % (time.strftime('%Y-%m-%d %H:%M:%S'), msg)

def readLines(fileName):
	return map(lambda x: x.strip(), file(fileName).readlines())

def runCommand(cmdLine):
	writeLog('Info: running %s' % cmdLine)
	os.system(cmdLine)

	
BIN_LOG_INDEX = '/var/lib/mysql/mysql-bin.index'
TEMP_FILE_NAME = '/web/storage/Logger/databaseWriteLog-%s-%s.tmp' % (socket.gethostname(), os.getpid())
LOG_COMPRESSOR = '/web/iTscripts/crons/log_compressor'

if len(sys.argv) < 3:
	print 'Usage: python %s <output file> <state file>' % os.path.basename(__file__)
	sys.exit(1)

outputFile = sys.argv[1]
stateFile = sys.argv[2]

writeLog('Info: started')

# get the list of binlog
existingFiles = readLines(BIN_LOG_INDEX)
existingFiles = map(lambda x: os.path.join(os.path.dirname(BIN_LOG_INDEX), x), existingFiles)
existingFiles = existingFiles[:-1]		# never index the active file

# remove already processed files
processedFiles = set(readLines(stateFile))
filesToProcess = filter(lambda x: os.path.basename(x) not in processedFiles, existingFiles)

if len(filesToProcess) == 0:
	writeLog('Info: no files to process')
	sys.exit(0)
	
# process the files
try:
	os.remove(TEMP_FILE_NAME)
except OSError:
	pass

for curFile in filesToProcess:
	runCommand('''mysqlbinlog %s | grep -a -v -e ^BEGIN -e ^END -e ^COMMIT -e '^SET @@session' -e '^use ' -e '^UPDATE `test`' -e '^#' -e '^\/\*!' | gawk '/^SET TIMESTAMP/ { if (l == 0) {split($0,a,"="); split(a[2],b,"/"); print "SET TIMESTAMP="strftime("%%Y-%%m-%%d %%H:%%M:%%S",b[1])}; l=1 } /^SET INSERT_ID/ {print $0} !/^SET TIMESTAMP/ && !/^SET INSERT_ID/ {print $0; l=0}' >> %s''' % (curFile, TEMP_FILE_NAME))

# compress the result
runCommand('%s -f %s' % (LOG_COMPRESSOR, TEMP_FILE_NAME))
os.remove(TEMP_FILE_NAME)

# copy the file to its final dest
runCommand('mv %s %s' % (TEMP_FILE_NAME + '.gz', outputFile))

# update the state file
file(stateFile, 'w').write('\n'.join(map(os.path.basename, existingFiles)))

writeLog('Info: done')
