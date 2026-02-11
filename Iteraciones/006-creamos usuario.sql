CREATE USER 
'campamento_verano'@'localhost' 
IDENTIFIED  BY 'campamento_verano';

GRANT USAGE ON *.* TO 'campamento_verano'@'localhost';

ALTER USER 'campamento_verano'@'localhost' 
REQUIRE NONE 
WITH MAX_QUERIES_PER_HOUR 0 
MAX_CONNECTIONS_PER_HOUR 0 
MAX_UPDATES_PER_HOUR 0 
MAX_USER_CONNECTIONS 0;

GRANT ALL PRIVILEGES ON `campamento_verano`.* 
TO 'campamento_verano'@'localhost';

FLUSH PRIVILEGES;
