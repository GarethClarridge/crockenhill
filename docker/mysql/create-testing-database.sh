#!/usr/bin/env bash

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS testing;
    CREATE DATABASE IF NOT EXISTS testing_dusk;
    GRANT ALL PRIVILEGES ON \`testing\`.* TO '$MYSQL_USER'@'%';
    GRANT ALL PRIVILEGES ON \`testing_dusk\`.* TO '$MYSQL_USER'@'%';
    GRANT ALL PRIVILEGES ON \`testing\\_%\`.* TO '$MYSQL_USER'@'%';
    GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test_%\`.* TO '$MYSQL_USER'@'%';
    GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\\_rehearsal%\`.* TO '$MYSQL_USER'@'%';
    FLUSH PRIVILEGES;
EOSQL
