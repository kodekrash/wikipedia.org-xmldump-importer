# wikipedia.org-xmldump-importer

Wikipedia.org XML Dump Importer is a script to import the standard Wikipedia XML dump into a simple MySQL/MariaDB database useful as a local cache for searching and manipulating Wikipedia articles. The database structure is designed for ease of use, and is not mediawiki-compatible.

This is an improved version of https://github.com/kodekrash/wikipedia.org-xmldump-mysql

https://github.com/kodekrash/wikipedia.org-xmldump-mongodb will be merged into this tool soon.

Dataset Source
--------------

URL: http://dumps.wikimedia.org/

Updates: monthly

Environment
-----------

* GNU/Linux
* PHP 5.4 + (with simplexml, bzip2, mysqli)
* MySQL 5.4 + (optional fulltext index option)

Notes
-----

* This script is designed to run on the command line and will refuse to run otherwise.
* enwiki download is approximately 12GB compressed and will require another (approx.) 50GB of storage for the database - a total of approximately 62GB.
* This script reads the compressed file.
* Import process requires approximately 4 hours on a well configured quad core with 4GB of memory. 

Usage
-----

	Options:
		--driver   Storage driver (mysql, dummy)
		--host     Storage server hostname/ip (default=localhost)
		--port     Storage server port (if not standard)
		--user     Storage server username (if required)
		--pass     Storage server password (if required)
		--name     Database/datastore name
		--file     Wikipedia.org XML dump file
		--schema   Show database schema SQL (MySQL driver only)
		--indexes  Show database index SQL (MySQL driver only)
		--import   Process file

Howto
-----

* Download the proper pages-articles XML file - for example, enwiki-20160204-pages-articles.xml.bz2.

* For MySQL/MariaDB
	* Create the database:

			echo "CREATE DATABASE my_database_name DEFAULT CHARACTER SET utf8 DEFAULT COLLATE utf8_bin;" | mysql

	* Get the schema from the script and import into your new database:

			./import.php --driver=mysql --schema | mysql my_database_name

	* Run the script, specifying database config and import file via command line options:

			./import.php --import --driver=mysql --host=localhost --user=dbuser --pass=mysecret --name=my_database_name --file=enwiki-20160204-pages-articles.xml.bz2

	* (Optional) Create indexes:

			./import.php --driver=mysql --indexes | mysql my_database_name

License
-------

This project is BSD (2 clause) licensed.

