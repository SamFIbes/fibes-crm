# Pipelines

Three boards: retail, business, and reseller.

Retail and business run Feasibility, Site survey, Proposal, Negotiation, then Won or Lost. An internal setup skips the site survey, the same way an HSI line would. FIBES does not sell HSI as its own service type.

Reseller runs Qualified, Due diligence, Proposal, Agreement, then Won or Lost. Agreement waits for someone in management, or the super admin, to move the card.

A proposal needs a covered check, or a survey marked feasible. A note on the card no longer unlocks it. A win needs a sales manager and a written reason. The signed contract arrives with quotes. Lost needs a reason. Price and postponed add one win-back task 75 days later. That number is `pipelines.winback_days` in the app config.

Opening a qualified lead creates the customer as a prospect when the phone is new, and links the customer when the phone is already on file. It also creates the primary contact and one deal. Doing it again does not create a second deal.

Stage time is written once, when the card leaves a stage. Those rows cannot be edited or deleted.
