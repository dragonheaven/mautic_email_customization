Mautic Email Channel Enhancement.

Added To Address & CC Address to email advance tab and allowed tokens in fields.
1. Use Case
Mautic defined only user's address as To address. And To address and CC can have only one recipient.
I customized to be enable custom To address (ie.owner's email or spouser's email), multiple To address and CC, BCC address separated by semi-colon(;) or comma(,).
So i.e. a if a contact visits a webpage, send an email about this information to the contact's owner and/or to wife.
Also can send email to custom fields that user defined in Mautic.
I also customized tokens.
By default, Mautic does not support Tokens for header fields, only for plain text fields.
I added funtions that can compile tokens and enable them into real values for header fields such as To address, CC address, ReplyTo, From address, From Name, Bcc address.
So users can use mixed form like {contactfield=wivesemail};johndoe@me.com,{contactfield=ownersemail}.
2. Token examples
{contactfield=email}
{contactfield=customfield}
{contactfield=customfield};email
