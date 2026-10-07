package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

@Entity
@Table(name = "predecessor")
@Getter
@Setter
@NoArgsConstructor
public class Predecessor {
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(nullable = false)
    private String operationId;

    @Column(nullable = false)
    private String predecessorId;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private DependencyType dependencyType = DependencyType.FINISH_TO_START;

    private String startCondition;

    /** Only mandatory predecessors gate readiness. */
    private boolean mandatory = true;

    private String description;
}
