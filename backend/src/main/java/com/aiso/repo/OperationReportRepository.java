package com.aiso.repo;

import com.aiso.domain.OperationReport;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface OperationReportRepository extends JpaRepository<OperationReport, Long> {
    List<OperationReport> findByOperationIdOrderByCreatedAtAsc(String operationId);

    List<OperationReport> findAllByOrderByCreatedAtDesc(Pageable pageable);

    long countByUserIdAndReportType(String userId, com.aiso.domain.ReportType reportType);
}
