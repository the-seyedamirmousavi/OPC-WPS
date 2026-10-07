package com.aiso.imports;

import com.aiso.domain.DependencyType;
import com.aiso.domain.OperationStatus;
import com.aiso.domain.Role;

import java.util.ArrayList;
import java.util.List;

/** Typed content of a workbook, independent of the source layout (master template or simple format). */
final class ParsedData {

    record ResRow(int row, String id, String name, String type, String userId, int capacity, String status, String description) {
    }

    record ItemRow(int row, String id, String name, double quantity, String unit, String supplyType, String relatedOperationId,
                   String description) {
    }

    record OpRow(int row, String id, String name, String itemId, String resourceId, String userId,
                 double prep, double transport, double setup, double direct, OperationStatus status, String description) {
    }

    record PredRow(int row, String operationId, String predecessorId, DependencyType type, String condition,
                   boolean mandatory, String description) {
    }

    record UserRow(int row, String id, String name, Role role, String messengerId, boolean active, String contact) {
    }

    record SettingRow(int row, String projectId, String projectName, String ownerId, String managerId,
                      String messengerPlatform, String dataVersion) {
    }

    String format;

    /** Sheet names used in error messages (they differ between the master template and the simple layout). */
    String resourceSheet = "Resources";
    String itemSheet = "BOM";
    String opSheet = "OPC";
    String predSheet = "Predecessors";
    String predColumn = "Predecessor_Operation_ID";
    final List<ResRow> resources = new ArrayList<>();
    final List<ItemRow> items = new ArrayList<>();
    final List<OpRow> ops = new ArrayList<>();
    final List<PredRow> preds = new ArrayList<>();
    final List<UserRow> users = new ArrayList<>();
    SettingRow settings;

    /** False when the file has no Predecessors sheet, in which case existing predecessors are left untouched. */
    boolean hasPredecessorSheet;

    final List<ImportIssue> errors = new ArrayList<>();
    final List<String> warnings = new ArrayList<>();

    void error(String sheet, int row, String column, String message) {
        errors.add(new ImportIssue(sheet, row, column, message));
    }
}
