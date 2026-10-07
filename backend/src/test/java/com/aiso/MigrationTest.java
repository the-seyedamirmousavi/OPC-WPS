package com.aiso;

import org.flywaydb.core.Flyway;
import org.junit.jupiter.api.Test;

import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.ResultSet;
import java.sql.Statement;

import static org.assertj.core.api.Assertions.assertThat;

/** An installation made with the single-project schema (V1) must upgrade without losing or orphaning data. */
class MigrationTest {

    private static final String URL = "jdbc:h2:mem:migration;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1";

    @Test
    void existingOperationsMoveIntoAProjectBuiltFromTheOldSettings() throws Exception {
        Flyway.configure().dataSource(URL, "sa", "").locations("classpath:db/migration").target("1").load().migrate();

        try (Connection c = DriverManager.getConnection(URL, "sa", ""); Statement st = c.createStatement()) {
            st.execute("UPDATE system_setting SET project_id = 'OLD-1', project_name = 'Legacy plant' WHERE id = 1");
            st.execute("INSERT INTO work_resource (id, name, capacity, status) VALUES ('R', 'Machine', 1, 'ACTIVE')");
            st.execute("INSERT INTO operation (id, name, resource_id, preparation_time, transport_time, setup_time, direct_time, "
                    + "status, progress_percent, completion_approved, created_at, updated_at, version) "
                    + "VALUES ('OP-1', 'Cut', 'R', 0, 0, 0, 5, 'READY', 0, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0)");
        }

        Flyway.configure().dataSource(URL, "sa", "").locations("classpath:db/migration").load().migrate();

        try (Connection c = DriverManager.getConnection(URL, "sa", ""); Statement st = c.createStatement()) {
            ResultSet p = st.executeQuery("SELECT id, name, priority, status FROM project");
            assertThat(p.next()).isTrue();
            assertThat(p.getString("id")).isEqualTo("OLD-1");
            assertThat(p.getString("name")).isEqualTo("Legacy plant");
            assertThat(p.getInt("priority")).isEqualTo(1);
            assertThat(p.getString("status")).isEqualTo("ACTIVE");
            assertThat(p.next()).isFalse();

            ResultSet o = st.executeQuery("SELECT project_id FROM operation WHERE id = 'OP-1'");
            assertThat(o.next()).isTrue();
            assertThat(o.getString(1)).isEqualTo("OLD-1");

            ResultSet l = st.executeQuery("SELECT language FROM system_setting WHERE id = 1");
            assertThat(l.next()).isTrue();
            assertThat(l.getString(1)).isEqualTo("en"); // existing installs keep their texts in English until the owner changes it
        }
    }

    @Test
    void anEmptyDatabaseGetsNoPlaceholderProject() throws Exception {
        String url = "jdbc:h2:mem:migration-empty;MODE=PostgreSQL;DATABASE_TO_LOWER=TRUE;DB_CLOSE_DELAY=-1";
        Flyway.configure().dataSource(url, "sa", "").locations("classpath:db/migration").load().migrate();
        try (Connection c = DriverManager.getConnection(url, "sa", ""); Statement st = c.createStatement()) {
            ResultSet r = st.executeQuery("SELECT COUNT(*) FROM project");
            assertThat(r.next()).isTrue();
            assertThat(r.getInt(1)).isZero();
        }
    }
}
