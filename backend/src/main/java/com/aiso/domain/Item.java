package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

@Entity
@Table(name = "item")
@Getter
@Setter
@NoArgsConstructor
public class Item {
    @Id
    @Column(length = 64)
    private String id;

    @Column(nullable = false, length = 200)
    private String name;

    private double quantity = 1;
    private String unit;
    private String supplyType;
    private String relatedOperationId;
    private String description;
}
