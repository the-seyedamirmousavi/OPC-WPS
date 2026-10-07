package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "assignment_run")
@Getter
@Setter
@NoArgsConstructor
public class AssignmentRun {
    @Id
    @Column(length = 36)
    private String id;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private AssignmentMode requestedMode;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private AssignmentMode effectiveMode;

    /** True when the LLM mode failed and the algorithm produced the proposals instead. */
    private boolean fallback;

    private String model;

    @Column(length = 2000)
    private String summary;

    @Column(length = 4000)
    private String warnings;

    @Column(length = 4000)
    private String unassigned;

    @Column(nullable = false)
    private String requestedBy;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();

    private long durationMs;
    private Long inputTokens;
    private Long outputTokens;
}
