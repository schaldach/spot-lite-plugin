<?php

/**
 * Singleton Database class to handle all database operations
 *
 * Manages the databse conection under wordpress standards and provides an API to interact with the database. 
 * Dont need to worry about sanitization, escaping or any other security issues, this class will handle it for you.
 * Dont need to worry about the database connection, this class will handle it for you.
 * Since this class is a singleton, you can access it from any part of your code using Spot_Lite_Database::get_instance()
 *
 * Since the wordpress database class is global, we dont need to worry about multiple connections, this class will handle
 *
 * @link       http://lite.acad.univali.br
 * @since      1.0.0
 *
 * @package    Spot_Lite
 * @subpackage Spot_Lite/includes/class-databases
 */
class Spot_Lite_Database
{

  private static $instance = null;
  private $wpdb;

  private function __construct()
  {
    global $wpdb;
    $this->wpdb = $wpdb;
  }

  public static function get_instance()
  {
    if (self::$instance == null) {
      self::$instance = new Spot_Lite_Database();
    }
    return self::$instance;
  }

  static public function get_table_name($table_name)
  {
    global $wpdb;
    return $wpdb->prefix . "spot_lite_" . $table_name;
  }

  protected function create_table_if_not_exists($table_name, $fields)
  {
    $table_name = self::get_table_name($table_name);
    $charset_collate = $this->wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (";
    foreach ($fields as $field) {
      $sql .= $field . ",";
    }
    $sql = rtrim($sql, ",");
    $sql .= ") $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private function drop_table_if_exists($table_name)
  {
    $table_name = self::get_table_name($table_name);
    $this->wpdb->query("DROP TABLE IF EXISTS $table_name");
  }

  private function create_index($table_name, $index_name, $fields)
  {
    $table_name = self::get_table_name($table_name);
    $index_name = $table_name . "_" . $index_name;

    // Check if the index already exists
    $index_exists = $this->wpdb->get_var(
      $this->wpdb->prepare(
        "SHOW INDEX FROM $table_name WHERE Key_name = %s",
        $index_name
      )
    );

    // If the index does not exist, create it
    if (!$index_exists) {
      $sql = "CREATE INDEX $index_name ON $table_name (";
      if (is_array($fields)) {
        foreach ($fields as $field) {
          $sql .= $field . ",";
        }
      } else {
        $sql .= $fields . ",";
      }
      $sql = rtrim($sql, ",");
      $sql .= ");";
      $this->wpdb->query($sql);
    }
  }

  private function insert($table_name, $data)
  {
    $table_name = self::get_table_name($table_name);
    $this->wpdb->insert($table_name, $data);
  }

  public function create_schema()
  {
    $this->create_table_if_not_exists('projects', [
      'id INT(11) NOT NULL AUTO_INCREMENT',
      'name VARCHAR(255) NOT NULL',
      'description TEXT',
      'start_date DATE',
      'end_date DATE',
      'status VARCHAR(50)',
      'PRIMARY KEY (id)'
    ]);
    $projects_table = self::get_table_name('projects');

    $this->create_table_if_not_exists('participants', [
      'id INT(11) NOT NULL AUTO_INCREMENT',
      'name VARCHAR(255) NOT NULL',
      'birth_date DATE',
      'school VARCHAR(255)',
      'PRIMARY KEY (id)'
    ]);
    $participants_table = self::get_table_name('participants');

    $this->create_table_if_not_exists('reports', [
      'id INT(11) NOT NULL AUTO_INCREMENT',
      'project_id INT(11) NOT NULL',
      'title VARCHAR(255) NOT NULL',
      'general_event_description TEXT',
      'event_date DATE',
      'author BIGINT UNSIGNED',
      'keywords_for_search TEXT',
      'PRIMARY KEY (id)',
      "FOREIGN KEY (project_id) REFERENCES $projects_table (id) ON DELETE CASCADE",
      'FOREIGN KEY (author) REFERENCES wp_users(ID) ON DELETE SET NULL'
    ]);
    $reports_table = self::get_table_name('reports');

    $this->create_table_if_not_exists('activities', [
      'id INT(11) NOT NULL AUTO_INCREMENT',
      'report_id INT(11) NOT NULL',
      'participant_id INT(11) NOT NULL',
      'description TEXT',
      'PRIMARY KEY (id)',
      "FOREIGN KEY (report_id) REFERENCES $reports_table(id) ON DELETE CASCADE",
      "FOREIGN KEY (participant_id) REFERENCES $participants_table(id) ON DELETE CASCADE"
    ]);

    $this->create_table_if_not_exists('photos', [
      'id INT(11) NOT NULL AUTO_INCREMENT',
      'url TEXT',
      'report_id INT(11) NOT NULL',
      'PRIMARY KEY (id)',
      "FOREIGN KEY (report_id) REFERENCES $reports_table(id) ON DELETE CASCADE",
    ]);

    $this->indexes();
    $this->create_full_text_search();
  }

  /**
   * Drop the general schema when deactivating the plugin
   * The deactivation hook is defined in the includes/class-spot-lite-deactivator.php file
   * 
   * @since    1.0.0
   */
  public function drop_schema()
  {
    $this->drop_table_if_exists('photos');
    $this->drop_table_if_exists('activities');
    $this->drop_table_if_exists('reports');
    $this->drop_table_if_exists('participants');
    $this->drop_table_if_exists('projects');
  }

  private function indexes()
  {

  }

  private function create_full_text_search()
  {
    $reports_table = self::get_table_name('reports');
    $column_exists = $this->wpdb->get_var("
      SELECT COUNT(*) 
      FROM information_schema.columns 
      WHERE table_name = '$reports_table' AND column_name = 'fulltext_search'
    ");

    if ($column_exists == 0) {
      $this->wpdb->query("ALTER TABLE $reports_table ADD COLUMN fulltext_search TEXT GENERATED ALWAYS AS (CONCAT(title, ' ', general_event_description, ' ', keywords_for_search)) STORED");
    }


    $index_exists = $this->wpdb->get_var("
      SELECT COUNT(*) 
      FROM information_schema.statistics 
      WHERE table_name = '$reports_table' AND index_name = 'reports_fulltext_search_index'
    ");

    if ($index_exists == 0) {
      $this->wpdb->query("CREATE FULLTEXT INDEX reports_fulltext_search_index ON $reports_table (fulltext_search) WITH PARSER ngram");
    }
  }

  public function insert_project($name, $description, $start_date, $end_date, $status)
  {
    $this->insert('projects', [
      'name' => $name,
      'description' => $description,
      'start_date' => $start_date,
      'end_date' => $end_date,
      'status' => $status
    ]);
  }


  public function insert_report($project_id, $title, $general_event_description, $event_date, $author, $keywords_for_search)
  {
    $this->insert('reports', [
      'project_id' => $project_id,
      'title' => $title,
      'general_event_description' => $general_event_description,
      'event_date' => $event_date,
      'author' => $author,
      'keywords_for_search' => $keywords_for_search
    ]);
  }

  public function insert_activity($report_id, $participant_id, $description, $start_date, $end_date, $status)
  {
    $this->insert('activities', [
      'report_id' => $report_id,
      'participant_id' => $participant_id,
      'description' => $description,
      'start_date' => $start_date,
      'end_date' => $end_date,
      'status' => $status
    ]);
  }

  public function insert_participant($name, $age, $school)
  {
    $this->insert('participants', [
      'name' => $name,
      'age' => $age,
      'school' => $school
    ]);
  }

  public function insert_photo($url, $report_id)
  {
    $this->insert('photos', [
      'url' => $url,
      'report_id' => $report_id
    ]);
  }

  public function full_text_search_reports($search)
  {
    $table_name = self::get_table_name('reports');
    $sql = "SELECT * FROM $table_name WHERE MATCH(fulltext_search) AGAINST (%s IN NATURAL LANGUAGE MODE)";
    return $this->wpdb->get_results($this->wpdb->prepare($sql, $search));
  }



  /// DEVELOPMENT ONLY

  public function populate()
  {
    $this->insert_project('Projeto 1', 'Descrição do projeto 1', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 2', 'Descrição do projeto 2', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 3', 'Descrição do projeto 3', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 4', 'Descrição do projeto 4', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 5', 'Descrição do projeto 5', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 6', 'Descrição do projeto 6', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 7', 'Descrição do projeto 7', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 8', 'Descrição do projeto 8', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 9', 'Descrição do projeto 9', '2021-01-01', '2021-12-31', 'Em andamento');
    $this->insert_project('Projeto 10', 'Descrição do projeto 10', '2021-01-01', '2021-12-31', 'Em andamento');


    $this->insert_participant('Participante 1', 20, 'Escola 1');
    $this->insert_participant('Participante 2', 21, 'Escola 2');
    $this->insert_participant('Participante 3', 22, 'Escola 3');


    $this->insert_report(1, 'Relatório 1', 'Descrição do evento 1', '2021-01-01', 1, 'robos');
    $this->insert_report(1, 'Relatório 2', 'Descrição do evento 2', '2021-01-01', 1, 'evento');
    $this->insert_report(1, 'Relatório 3', 'Descrição do evento 3', '2021-01-01', 1, 'evento');

    $this->insert_activity(1, 1, 'Atividade 1', '2021-01-01', '2021-01-02', 'Concluída');
    $this->insert_activity(1, 1, 'Atividade 2', '2021-01-01', '2021-01-02', 'Concluída');
    $this->insert_activity(1, 1, 'Atividade 3', '2021-01-01', '2021-01-02', 'Concluída');

    $this->insert_photo('https://via.placeholder.com/150', 1);
    $this->insert_photo('https://via.placeholder.com/150', 1);
  }

  public function clear_all()
  {
    $this->drop_schema();
    $this->create_schema();
  }
}