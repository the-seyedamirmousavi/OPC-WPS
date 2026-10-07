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
@Table(name = "system_setting")
@Getter
@Setter
@NoArgsConstructor
public class SystemSetting {
    @Id
    private Integer id = 1;

    private String projectId;
    private String projectName;
    private String ownerId;
    private String managerId;

    @Column(nullable = false, length = 30)
    private String messengerPlatform = "IN_APP";

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private SystemStatus systemStatus = SystemStatus.ACTIVE;

    private String dataVersion;
    private int configurationVersion = 1;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private AssignmentMode assignmentMode = AssignmentMode.ALGORITHM;

    private int maxActiveTasksPerUser = 3;

    /** Language of texts the system stores or generates for people: "fa" (Persian) or "en". */
    @Column(nullable = false, length = 5)
    private String language = "en";

    /** When true, dependants unlock only after a manager approves the predecessor's completion. */
    private boolean requireCompletionApproval;

    @Column(nullable = false)
    private Instant updatedAt = Instant.now();
}
