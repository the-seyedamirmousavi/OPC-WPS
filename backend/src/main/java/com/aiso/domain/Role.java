package com.aiso.domain;

public enum Role {
    OWNER, MANAGER, USER_1, USER_2, USER_3;

    /** Executive users who receive and perform tasks. */
    public boolean isExecutor() {
        return this == USER_1 || this == USER_2 || this == USER_3;
    }
}
