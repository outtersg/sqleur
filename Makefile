sqlminus.jar: eu/outters/sqleur/SqlMinus.java
	[ -d ../OpenCSV ] && ( cd ../OpenCSV && make ) && rm -f opencsv.jar && ln ../OpenCSV/build/opencsv.jar ./ || true
	javac -cp opencsv.jar $<
	jar cf $@ eu/outters/sqleur/*.class && rm eu/outters/sqleur/*.class
