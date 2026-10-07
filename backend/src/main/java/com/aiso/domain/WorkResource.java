package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

@Entity
@Table(name = "work_resource")
@Getter
@Setter
@NoArgsConstructor
public class WorkResource {
    @Id
    @Column(length = 64)
    private String id;

    @Column(nullable = false, length = 200)
    private String name;

    private String resourceType;
    private String responsibleUserId;

    /** Number of operations this resource can run simultaneously. */
    private int capacity = 1;

    /** ACTIVE or INACTIVE. */
    @Column(nullable = false, length = 20)
    private String status = "ACTIVE";

    private String description;

    public boolean isActive() {
        return "ACTIVE".equals(status);
    }
}
