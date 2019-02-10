import React from "react";
import Grid from "@material-ui/core/Grid/Grid";
import TextField from "@material-ui/core/TextField/TextField";
import Paper from "@material-ui/core/Paper/Paper";
import moment from "moment-timezone";
import FormControl from "@material-ui/core/FormControl/FormControl";
import InputLabel from "@material-ui/core/InputLabel/InputLabel";
import Select from "@material-ui/core/Select/Select";
import Input from "@material-ui/core/Input/Input";
import MenuItem from "@material-ui/core/MenuItem/MenuItem";
import ClearableTextField from "../ClearableTextField";
import { DateTimePicker } from "material-ui-pickers";
import { Datepicker } from "../Datepicker";

export default class APILogsParameters extends React.Component {
  state = {
    isFromTimeValid: true,
    isToTimeValid: true
  };

  filterParameters = parameters => {
    return Object.keys(parameters).reduce((acc, parameterName) => {
      if (
        [
          "type",
          "fromTime",
          "toTime",
          "textFilter",
          "session",
          "server",
          "logTypes"
        ].indexOf(parameterName) !== -1
      ) {
        acc[parameterName] = parameters[parameterName];
      }
      return acc;
    }, {});
  };

  validate = () => {
    const isFromTimeValid = this._validateDate("fromTime", "isFromTimeValid");
    const isToTimeValid = this._validateDate("toTime", "isToTimeValid");
    return isFromTimeValid && isToTimeValid;
  };

  _validateDate = (propertyName, validStateName) => {
    const value = this.props[propertyName];
    const isValid = value && moment(value).isValid();
    this.setState({
      [validStateName]: isValid
    });

    return isValid;
  };

  render() {
    const {
      textFilter,
      session,
      server,
      logTypes,
      fromTime,
      toTime,
      onChange,
      onClear,
      className: classNameProp,
      onTextFilterChange
    } = this.props;
    const { isFromTimeValid, isToTimeValid } = this.state;
    const InvalidDateMessage = () => <span>Date is missing or invalid</span>;
    console.log(fromTime);
    return (
      <Paper elevation={1} className={classNameProp}>
        <Grid container spacing={16}>
          <Grid item xs={4}>
            <Datepicker
              keyboard
              name="fromTime"
              label="From Time"
              format={"YYYY-MM-DD hh:mm"}
              invalidDateMessage={<InvalidDateMessage />}
              fullWidth
              onBlur={() => this._validateDate("fromTime", "isFromTimeValid")}
              value={fromTime}
              onChange={onChange}
            />
          </Grid>
          <Grid item xs={4}>
            <Datepicker
              fullWidth
              keyboard
              name="toTime"
              label="To Time"
              value={toTime}
              onChange={onChange}
              invalidDateMessage={<InvalidDateMessage />}
              onBlur={() => this._validateDate("toTime", "isToTimeValid")}
              InputLabelProps={{
                shrink: true
              }}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              label="Search Criteria"
              name={"textFilter"}
              onClear={() => onTextFilterChange("")}
              value={textFilter.text}
              onChange={e => onTextFilterChange(e.target.value)}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={onClear}
              name="server"
              label="Server"
              value={server}
              onChange={onChange}
              InputLabelProps={{
                shrink: true
              }}
            />
          </Grid>
          <Grid item xs={4}>
            <ClearableTextField
              fullWidth
              onClear={onClear}
              name="session"
              label="Session"
              value={session}
              onChange={onChange}
              InputLabelProps={{
                shrink: true
              }}
            />
          </Grid>
          <Grid item xs={4}>
            <FormControl fullWidth>
              <InputLabel>Log Types</InputLabel>
              <Select
                value={logTypes}
                onChange={onChange}
                input={<Input name="logTypes" id="type-input" />}
              >
                <MenuItem value={"apiV3, ps2"}>apiV3, ps2</MenuItem>
                <MenuItem value={"apiV3"}>apiV3</MenuItem>
                <MenuItem value={"ps2"}>ps2</MenuItem>
              </Select>
            </FormControl>
          </Grid>
        </Grid>
      </Paper>
    );
  }
}
