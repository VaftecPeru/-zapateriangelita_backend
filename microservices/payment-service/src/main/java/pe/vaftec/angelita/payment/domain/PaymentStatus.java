package pe.vaftec.angelita.payment.domain;

public enum PaymentStatus {
    CREATED,
    CHARGE_REQUESTED,
    PROCESSING,
    REQUIRES_3DS,
    COMPLETED,
    FAILED,
    UNKNOWN
}
