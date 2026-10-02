-- Extra database used when running the test-suite against MySQL inside Docker.
CREATE DATABASE IF NOT EXISTS mzian_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON mzian_test.* TO 'mzian'@'%';
FLUSH PRIVILEGES;
