package com.aiso.imports;

/** One validation finding, always reported with Sheet, Row (1-based, as shown in Excel), Column and a description. */
public record ImportIssue(String sheet, int row, String column, String message) {
}
