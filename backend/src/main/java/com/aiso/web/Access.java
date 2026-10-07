package com.aiso.web;

/** Role expressions for @PreAuthorize. */
public final class Access {

    public static final String OWNER = "hasRole('OWNER')";
    public static final String MANAGEMENT = "hasAnyRole('OWNER','MANAGER')";
    public static final String MANAGER_ONLY = "hasRole('MANAGER')";
    public static final String EXECUTOR = "hasAnyRole('USER_1','USER_2','USER_3')";

    private Access() {
    }
}
