# firemedia

This project is a very crude work in progress.

Someday it might be a simple tool for media management including remote access for streaming clients.

## Setup
To setup the app follow these steps:
* git clone git@github.com:phroust/firemedia.git
* composer install

There is no setup wizard yet so you need to define an .env file (use .env.dist as skeleton) to set your database 
connection string in **DATABASE_URL**.
Currently only MySQL is supported.

Database creation and updates are handled by doctrine.
* ./bin/console doctrine:migrations:migrate

## Usage
At this moment there are only two console commands to work with.

### Create library
*./bin/console firemedia:library:add*

This commands is interactive and asks for a name and a path.
The name can be any string. 
The path must be a local directory accessible by PHP.

### Scan library
* ./bin/console firemedia:library:scan <library_id>*

This command scans a given library for new files. Metadata is extracted and written to the database. 
Known files are omitted (use *--forceUpdate* to reset existing metadata).

