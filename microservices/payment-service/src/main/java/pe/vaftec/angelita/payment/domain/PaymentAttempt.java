package pe.vaftec.angelita.payment.domain;

import jakarta.persistence.*;
import java.math.BigDecimal;
import java.time.Instant;
import java.util.UUID;

@Entity
@Table(name = "payment_attempts", indexes = {
    @Index(name = "idx_payment_order", columnList = "order_id"),
    @Index(name = "idx_payment_transaction", columnList = "transaction_id")
})
public class PaymentAttempt {
    @Id
    @Column(length = 36)
    private String id;

    @Column(name = "order_id", nullable = false)
    private Long orderId;

    @Column(name = "order_code", nullable = false, length = 64)
    private String orderCode;

    @Column(nullable = false, unique = true, length = 120)
    private String reference;

    @Column(name = "idempotency_key", nullable = false, unique = true, length = 180)
    private String idempotencyKey;

    @Column(name = "transaction_id", length = 120)
    private String transactionId;

    @Column(nullable = false, precision = 12, scale = 2)
    private BigDecimal amount;

    @Column(nullable = false, length = 3)
    private String currency;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 32)
    private PaymentStatus status;

    @Column(name = "openpay_status", length = 64)
    private String openpayStatus;

    @Column(name = "redirect_url", length = 1000)
    private String redirectUrl;

    @Column(name = "error_code", length = 32)
    private String errorCode;

    @Column(name = "error_message", length = 1200)
    private String errorMessage;

    @Column(name = "guest_checkout", nullable = false)
    private boolean guestCheckout;

    @Column(name = "notification_sent", nullable = false)
    private boolean notificationSent;

    @Column(name = "voucher_generated", nullable = false)
    private boolean voucherGenerated;

    @Column(name = "created_at", nullable = false)
    private Instant createdAt;

    @Column(name = "updated_at", nullable = false)
    private Instant updatedAt;

    public static PaymentAttempt create(Long orderId, String orderCode, String reference, BigDecimal amount, String currency, boolean guestCheckout) {
        PaymentAttempt p = new PaymentAttempt();
        p.id = UUID.randomUUID().toString();
        p.orderId = orderId;
        p.orderCode = orderCode;
        p.reference = reference;
        p.idempotencyKey = "ANGELITA:" + orderId + ":" + reference;
        p.amount = amount;
        p.currency = currency;
        p.guestCheckout = guestCheckout;
        p.status = PaymentStatus.CREATED;
        p.createdAt = Instant.now();
        p.updatedAt = p.createdAt;
        return p;
    }

    @PreUpdate
    public void touch() { this.updatedAt = Instant.now(); }

    public String getId() { return id; }
    public Long getOrderId() { return orderId; }
    public String getOrderCode() { return orderCode; }
    public String getReference() { return reference; }
    public String getIdempotencyKey() { return idempotencyKey; }
    public String getTransactionId() { return transactionId; }
    public BigDecimal getAmount() { return amount; }
    public String getCurrency() { return currency; }
    public PaymentStatus getStatus() { return status; }
    public String getOpenpayStatus() { return openpayStatus; }
    public String getRedirectUrl() { return redirectUrl; }
    public String getErrorCode() { return errorCode; }
    public String getErrorMessage() { return errorMessage; }
    public boolean isGuestCheckout() { return guestCheckout; }
    public boolean isNotificationSent() { return notificationSent; }
    public boolean isVoucherGenerated() { return voucherGenerated; }

    public void setTransactionId(String v) { transactionId = v; }
    public void setStatus(PaymentStatus v) { status = v; }
    public void setOpenpayStatus(String v) { openpayStatus = v; }
    public void setRedirectUrl(String v) { redirectUrl = v; }
    public void setErrorCode(String v) { errorCode = v; }
    public void setErrorMessage(String v) { errorMessage = v; }
    public void setNotificationSent(boolean v) { notificationSent = v; }
    public void setVoucherGenerated(boolean v) { voucherGenerated = v; }
}
