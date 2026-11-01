# Orders

A won deal opens one order and one field job in the Requested queue on the dispatch board. The lines come from the accepted quote when there is a signed contract. The same order can be an upgrade, a downgrade, a move, an add-on, a termination, or a reactivation.

The path is submitted, credit check, ready to schedule, scheduled, in progress, installed, activated, then completed. Putting it on hold or cancelling needs a reason. Cancelling gives the reserved equipment back, and the stock book records that release without changing older rows. Scheduling equipment needs a reservation. If the warehouse is short, a draft purchase order is opened.

Activation needs a RADIUS username. It marks the service active and leaves a task to create the subscriber in Krypton, because Krypton has no connection here. The same step opens the subscription and the first invoice, which closes the “first invoice recorded” check. Payments themselves are a later step.

A termination, and an upgrade or downgrade that changes the monthly price, waits in the approval inbox before a job is created. A termination also opens an equipment-return job. The return charge is a placeholder.

WhatsApp links are built only when the customer has agreed. Email is sent when an address is on file, at scheduling, when the visit is underway, and when the order is complete.

Purchase orders use the existing approval rule. Above the automatic limit they cannot be sent until someone else approves. Receiving adds exactly that quantity to stock.

The install target is 5 business days. A deposit before scheduling is off. Both are settings Sam can change.
