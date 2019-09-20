<?php

class Cloudflare_Db
{
    protected $con = null;

    public function __construct()
    {
    }

    /**
     * Run all migrations from the specified version up out of libraries/db/migrations/*.sql
     */
    public function processMigrations($version)
    {

        $migration_dir = dirname(__FILE__) . "/db/migrations/";

        $migration_dirfiles = scandir($migration_dir);

        $file_ending = ".sql";
        $valid_migrations = array();
        foreach ($migration_dirfiles as $file) {
            // check that the file ends with ".sql"
            if ((($temp = strlen($file) - strlen($file_ending)) >= 0 && strpos($file, $file_ending, $temp) !== false)) {
                // if it is a valid file, strip the .sql
                array_push($valid_migrations, substr($file, 0, -4));
            }
        }

        // sort the migrations so that they process in version order
        usort($valid_migrations, array(self, "versionSort"));

        // finally compare each file to the last installed version and process sql statements
        foreach ($valid_migrations as $file) {
            if (version_compare($version, $file, '<') === true) {
                $queries = $this->parseFile($migration_dir . '/' . $file . '.sql');

                foreach ($queries as $query) {
                    mysql_query($query);
                }
            }
        }

        return true;
    }

    public function install()
    {
        $queries = $this->parseFile(dirname(__FILE__) . "/db/schema.sql");

        foreach ($queries as $query) {
            mysql_query($query);
        }

        return true;
    }

    public function uninstall()
    {
        $queries = $this->parseFile(dirname(__FILE__) . "/db/uninstall.sql");

        foreach ($queries as $query) {
            mysql_query($query);
        }

        return true;
    }

    /* -- Internal helper functions -- */

    // sort items based on version_compare
    static protected function versionSort($ver1, $ver2)
    {
        return version_compare($ver1, $ver2);
    }

    // very simple sql file parser
    protected function parseFile($file)
    {
        $query_string = file_get_contents($file);

        // strip out comments so they don't actually get processed
        // This supports removing multi-line comments, as well as single line comments starting with # or --
        $query_string = preg_replace('/\/\*(.|[\r\n])*?\*\//', '', $query_string);
        $query_string = preg_replace('/(#|--)(.*)/', '', $query_string);


        $queries = explode(';', $query_string);

        foreach ($queries as $key => $query) {
            // remove white space before checking that we actually have a query to run
            $query = trim($query);
            if ($query === "") {
                unset($queries[$key]);
            } else {
                $queries[$key] = $query;
            }
        }

        return $queries;
    }
}
