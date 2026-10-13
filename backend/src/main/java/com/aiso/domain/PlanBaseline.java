package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

/** One operation of the reference plan the scheduler keeps the schedule close to (see V4 migration). */
@Entity
@Table(name = "plan_baseline")
@Getter
@Setter
@NoArgsConstructor
public class PlanBaseline {
    @Id
    @Column(length = 64)
    private String operationId;

    @Column(nullable = false)
    private Instant plannedStart;

    @Column(nullable = false)
    private Instant plannedEnd;

    @Column(nullable = false)
    private Instant savedAt;
}
