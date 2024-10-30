<?php
declare(strict_types=1);

namespace LockmeIntegration\Util;

use LockmeIntegration\Libs\WP_List_Table;
use WP_Query;

class LogTable extends WP_List_Table
{
    public function get_columns(): array
    {
        return [
            'id' => 'Log ID',
            'time' => 'Time',
            'method' => 'HTTP method',
            'uri' => 'HTTP URI',
            'params' => 'Request body',
            'response' => 'Response'
        ];
    }

    protected function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'params':
            case 'response':
                return '<pre>'.$item[$column_name].'</pre>';
            default:
                return $item[$column_name];
        }
    }

    protected function get_table_classes(): array
    {
        return array( 'widefat', 'striped' );
    }

    public function no_items(): void
    {
        echo 'The logs are empty. And this is a good thing!';
    }

    public function prepare_items(): void {
        global $wpdb;
        // code to handle bulk actions

        //used by WordPress to build and fetch the _column_headers property
        $this->_column_headers = [$this->get_columns(), [], []];

        $wpdb_table = $wpdb->prefix . 'lockme_log';
        $orderby = ( isset( $_GET['orderby'] ) ) ? esc_sql( $_GET['orderby'] ) : 'id';
        $order = ( isset( $_GET['order'] ) ) ? esc_sql( $_GET['order'] ) : 'DESC';

        $items = 10;
        $page = ($this->get_pagenum() - 1) * $items;

        $query = "SELECT
            *
        FROM $wpdb_table
        ORDER BY $orderby $order
        LIMIT $page,$items";

        // query output_type will be an associative array with ARRAY_A.
        $this->items = $wpdb->get_results( $query, ARRAY_A  );

        $total = $wpdb->get_var("SELECT count(*) FROM {$wpdb_table}");

        // code to handle data operations like sorting and filtering

        // code to handle pagination
        $this->set_pagination_args( array (
            'total_items' => $total,
            'per_page'    => $items,
            'total_pages' => ceil( $total/$items )
        ) );
    }
}
