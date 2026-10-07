package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "import_log")
@Getter
@Setter
@NoArgsConstructor
public class ImportLog {
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(nullable = false)
    private Instant importedAt = Instant.now();

    @Column(nullable = false)
    private String actorId;

    private String fileName;

    @Column(name = "file_format", length = 20)
    private String format;

    /** REJECTED, VALID (dry run) or APPLIED. */
    @Column(nullable = false, length = 20)
    private String status;

    private int errorCount;

    @Column(length = 2000)
    private String summary;

    @Column(length = 9000)
    private String details;
}
